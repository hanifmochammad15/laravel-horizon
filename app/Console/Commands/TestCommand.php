<?php

namespace App\Console\Commands;

use App\Facades\ES;
use App\Facades\Log;
use Illuminate\Support\Str;
use App\Services\LogService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Contracts\Database\Query\Builder;

class TestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tester';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {



 $paramTgl ='2022-12-30';
//         $insert = DB::table('merchant_data')
//         ->select(['mid','nama_merchant','channel','sub_channel','no_rek','email','ebor_mode'])
//         ->addSelect(DB::raw("'$paramTgl' as tgl_trx"))
//         ->addSelect(DB::raw("-1 as status"))
//         ->where('email','!=','')
//         ->where('ebor_mode','1')
//         ->groupBy('mid')
//         ->orderBy('mid','asc')
// ->chunk(1000,function(Collection $collections){

// $data = [];

// $newData = [];


// foreach($collections as $collection){
//     $newData['mid'] = $collection->mid;
//     $newData['nama_merchant'] = $collection->nama_merchant;
//     $newData['channel'] = $collection->channel;
//     $newData['sub_channel'] = $collection->sub_channel;
//     $newData['no_rek'] = $collection->no_rek;
//     $newData['email'] = $collection->email;
//     $newData['status'] = $collection->status;
//     $newData['tgl_trx'] = $collection->tgl_trx;
//     $newData['ebor_mode'] = $collection->ebor_mode;

//     $data[] = $newData;
// }
//     DB::table('job_ebor')
//     ->insert($data);

// });
$tglTrx='2022-04-10';

$ebor =  DB::table('job_ebor')
->where('tgl_trx',$tglTrx )
->first();

$resultGenerate = [];
$total_sukses = 0;
$getMerchantData =  DB::table('merchant_data')
->where('mid',$ebor->mid )
->first();

if(!empty($getMerchantData)){
    $dbEbor = DB::connection('sqlsrv');
    $getDataRk = $dbEbor->select('EXEC SP_EBOR_DDHIST ?,?',array($tglTrx, $getMerchantData->no_rek));
    $getDataDetailRk = $dbEbor->select('EXEC SP_EBOR_DETAIL_RK_NEW ?,?',array($tglTrx, $getMerchantData->no_rek));
    $basePath = storage_path('app/ebor_generate/');
    $zip = new \ZipArchive();

    $dataemail = array(
        'email_type'		=> 'EBR',
        'appname'			=> 'mms',
        'email_from'		=> 'BankBRI@bri.co.id',
        'alias_from' 		=> 'BANK BRI-BUSINESS ACQUIRING',
        'subject'			=> '[BRI] REPORT TRANSAKSI EDC ['.$ebor->tgl_trx.']',
        'content'			=> 'Dear Merchant BRI,<br><br>Berikut ini disampaikan laporan transaksi harian <br>EDC merchant '.$ebor->nama_merchant.' per tanggal '.$ebor->tgl_trx.'<br><br><br>Note: mohon untuk tidak membalas email ini, karena email ini hanya untuk sarana informasi',
        'inserted'			=> date('Y-m-d H:i:s')
    );

    if ($getMerchantData->ebor_mode == 1 || $ebor->is_mma == 1){
        $format ='pdf';

        $resultGenerate = $this->generatePdf($tglTrx, $getMerchantData,$getDataRk,$getDataDetailRk);
        if (Storage::disk('ebor')->exists('temp_pdf/'.$resultGenerate['rk_file']) && Storage::disk('ebor')->exists('temp_pdf/'.$resultGenerate['detail_file']) ) {
            $zip_file_name = $getMerchantData->mid.'_'.$tglTrx.'_'.$format.'.zip';
            $zip_file = $basePath. $zip_file_name;
            // Initializing PHP class
            $zip->open($zip_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            // Adding file: second parameter is what will the path inside of the archive
            // So it will create another folder called "storage/" inside ZIP, and put the file there.
            $zip->addFile($basePath.'temp_pdf/'.$resultGenerate['rk_file'], $resultGenerate['rk_file']);
            $zip->addFile($basePath.'temp_pdf/'.$resultGenerate['detail_file'], $resultGenerate['detail_file']);
            $zip->close();
            if($getMerchantData->ebor_mode == 1 ){
                if(Storage::disk('ebor')->exists($zip_file_name)){
                    Storage::disk('remote_sftp')->put('files/mms/'.$zip_file_name, $zip_file, 'public');
                }

                $email_to_arr = explode(',', preg_replace("/\r|\n/", "", $ebor->email));
				$result_ins = 0;
                $dbEmail = DB::connection('email_notifikasi');
                foreach ($email_to_arr as $key => $email_tujuan) {
                    if (filter_var(trim($email_tujuan), FILTER_VALIDATE_EMAIL)) {
                        $arr_email_name = explode('@', $email_tujuan);
                        $dataemail['attachment'] = $zip_file_name;
                        $dataemail['email_to'] = $email_tujuan;
                        $dataemail['alias_to'] = strtoupper($ebor->nama_merchant);
                        $ins = $dbEmail->table('email_master')->insert($dataemail);
                        if($ins)
                            $result_ins +1;
                        $total_sukses++;
                    }

                }
            }
            if( $ebor->is_mma == 1){
                // procces is_mma ->zip_file->encode->base64 and save zip file to monggosh
                $fileB64 = base64_encode(file_get_contents($zip_file ));
                dd($fileB64);
            }
            //delete file temp & zip file
            Storage::disk('ebor')->delete('temp_pdf/'.$resultGenerate['rk_file']);
            Storage::disk('ebor')->delete('temp_pdf/'.$resultGenerate['detail_file']);
            Storage::disk('ebor')->delete($zip_file_name);
        }

    }

    if($getMerchantData->ebor_mode == 2 || $ebor->is_mma == 1){
        $format ='csv';

        $resultGenerate = $this->generateCsv($tglTrx, $getMerchantData,$getDataRk,$getDataDetailRk);
        if (Storage::disk('ebor')->exists('temp_csv/'.$resultGenerate['rk_file']) && Storage::disk('ebor')->exists('temp_csv/'.$resultGenerate['detail_file']) ) {
            $zip_file_name = $getMerchantData->mid.'_'.$tglTrx.'_'.$format.'.zip';
            $zip_file = $basePath. $zip_file_name;
            // Initializing PHP class
            $zip_file = $basePath.$getMerchantData->mid.'_'.$tglTrx.'_'.$format.'.zip';
            $zip->open($zip_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            // Adding file: second parameter is what will the path inside of the archive
            // So it will create another folder called "storage/" inside ZIP, and put the file there.
            $zip->addFile($basePath.'temp_csv/'.$resultGenerate['rk_file'], $resultGenerate['rk_file']);
            $zip->addFile($basePath.'temp_csv/'.$resultGenerate['detail_file'], $resultGenerate['detail_file']);
            $zip->close();
            if($getMerchantData->ebor_mode == 2 ){
                if(Storage::disk('ebor')->exists($zip_file_name)){
                    Storage::disk('remote_sftp')->put('files/mms/'.$zip_file_name, $zip_file, 'public');
                }
                $email_to_arr = explode(',', preg_replace("/\r|\n/", "", $ebor->email));
				$result_ins = 0;
                $dbEmail = DB::connection('email_notifikasi');
                foreach ($email_to_arr as $key => $email_tujuan) {
                    if (filter_var(trim($email_tujuan), FILTER_VALIDATE_EMAIL)) {
                        $arr_email_name = explode('@', $email_tujuan);
                        $dataemail['attachment'] = $zip_file_name;
                        $dataemail['email_to'] = $email_tujuan;
                        $dataemail['alias_to'] = strtoupper($ebor->nama_merchant);
                        $ins = $dbEmail->table('email_master')->insert($dataemail);
                        if($ins)
                            $result_ins +1;
                        $total_sukses++;
                    }

                }
            }
            if( $ebor->is_mma == 1){
                // procces is_mma ->zip_file->encode->base64 and save zip file to monggosh db
                $fileB64 = base64_encode(file_get_contents($zip_file ));
                dd($fileB64);
            }
            //delete file temp & zip file
            Storage::disk('ebor')->delete('temp_csv/'.$resultGenerate['rk_file']);
            Storage::disk('ebor')->delete('temp_csv/'.$resultGenerate['detail_file']);
            Storage::disk('ebor')->delete($zip_file_name);
        }
    }

}



}

    public function generateCsv($tglTrx,  $merchant, $getDataRk, $getDataDetailRk){
        // logic generate csv
        $resultGenerate =[];
        $format ='csv';

        $basePath = storage_path('app/ebor_generate/');

        $csvRK = $this->rkCsv($tglTrx,  $merchant, $getDataRk );
        $csvDetailRk = $this->detailCsv($tglTrx,  $merchant, $getDataDetailRk );

        $filenameRkCsv = $merchant->mid.'_file_rk_'.$tglTrx.'.'.$format;
        $filenameDetailRkCsv = $merchant->mid.'_file_detail_rk_'.$tglTrx.'.'.$format;

        Storage::disk('ebor')->put('temp_csv/'.$filenameRkCsv,  $csvRK);
        Storage::disk('ebor')->put('temp_csv/'.$filenameDetailRkCsv,  $csvDetailRk);

        $filerkCsvPath= $basePath.'temp_csv/'.$filenameRkCsv;
        $fileDetailrkCsvPath= $basePath.'temp_csv/'.$filenameDetailRkCsv;

        $resultGenerate ['format'] =$format;
        $resultGenerate ['rk_file'] = 'failed create RK CSV';
        $resultGenerate ['detail_file'] = 'failed create Detail RK CSV';
        if (Storage::disk('ebor')->exists('temp_csv/'. $filenameRkCsv)){
            $resultGenerate ['rk_file'] = $filenameRkCsv;
        }
        if ( Storage::disk('ebor')->exists('temp_csv/'. $filenameDetailRkCsv)){
            $resultGenerate ['detail_file'] = $filenameDetailRkCsv;
        }


        return $resultGenerate;
    }

    public function rkCsv($tglTrx,  $merchant, $getDataRk){
        $merchant_alamat = $merchant->alamat_merchant.", ".trim($merchant->kelurahan).", ".trim($merchant->kecamatan).", ".trim($merchant->kabupaten).", ".trim($merchant->provinsi).", ".trim($merchant->kodepos);
        $csvFile ='';
        $csvFile .='"Nama Pemilik Rekening";"'. $merchant->pemilik_rek.'"';
        $csvFile .="\n";
        $csvFile .='"No Rekening";"'. $merchant->no_rek.'"';
        $csvFile .="\n";
        $csvFile .='"Alamat";"'. $merchant_alamat.'"';
        $csvFile .="\n";
        $csvFile .='"NOREK";"BRANCH";"TRXDATE";"TRXTIME";"DORC";"AUXTRC";"AMT";"REMARK";"SEQ";"MID"';
        $csvFile .="\n";

        foreach ($getDataRk as $key) {
                $csvFile .='"'.$key->NOREK.'";';
                $csvFile .='"'.$key->BRANCH.'";';
                $csvFile .='"'.$key->TRXDATE.'";';
                $csvFile .='"'.$key->TRXTIME.'";';
                $csvFile .='"'.$key->DORC.'";';
                $csvFile .='"'.$key->AUXTRC.'";';
                $csvFile .='"'.$key->AMT.'";';
                $csvFile .='"'.$key->REMARK.'";';
                $csvFile .='"'.$key->SEQ.'";';
                $csvFile .='"'.$key->MID.'"';
                $csvFile .="\n";
            }

        return $csvFile;
    }

    public function detailCsv($tglTrx,  $merchant, $getDataDetailRk){
        $merchant_alamat = $merchant->alamat_merchant.", ".trim($merchant->kelurahan).", ".trim($merchant->kecamatan).", ".trim($merchant->kabupaten).", ".trim($merchant->provinsi).", ".trim($merchant->kodepos);
        $csvFile ='';
        $csvFile .='"Nama Pemilik Rekening";"'. $merchant->pemilik_rek.'"';
        $csvFile .="\n";
        $csvFile .='"No Rekening";"'. $merchant->no_rek.'"';
        $csvFile .="\n";
        $csvFile .='"Alamat";"'. $merchant_alamat.'"';
        $csvFile .="\n";
        $csvFile .='"MID";"TID";"NAMA_MERCHANT";"TGL_TRX";"TGL_SETL";"CARD_NUMBER";"REMARK_RK";"AMT_SETL";"AMT_TRX";"RATE";"DISC_AMT";"NET_AMT";"TIPE";"PRINCIPLE";"ISSUER";"APRV_CODE/REFF_NUM";"BATCH_NUM";"AMT_NONFARE";"TGL_RK"';
        $csvFile .="\n";
        foreach ($getDataDetailRk as $key) {
                $csvFile .='"'.$key->MID.'";';
                $csvFile .='"'.$key->TID.'";';
                $csvFile .='"'.$key->NAMA_MERCHANT.'";';
                $csvFile .='"'.$key->TGL_TRX.'";';
                $csvFile .='"'.$key->TGL_SETL.'";';
                $csvFile .='"'.$key->CARD_NUMBER.'";';
                $csvFile .='"'.$key->REMARK_RK.'";';
                $csvFile .='"'.$key->AMT_SETL.'";';
                $csvFile .='"'.$key->DISC_AMT.'";';
                $csvFile .='"'.$key->AMT_TRX.'";';
                $csvFile .='"'.$key->RATE.'";';
                $csvFile .='"'.$key->DISC_AMT.'";';
                $csvFile .='"'.$key->NET_AMT.'";';
                $csvFile .='"'.$key->TIPE.'";';
                $csvFile .='"'.$key->PRINCIPLE.'";';
                $csvFile .='"'.$key->ISSUER.'";';
                $csvFile .='"'.$key->APRV_CODE_OR_REFF_NO.'";';
                $csvFile .='"'.$key->BATCH_NUM.'";';
                $csvFile .='"'.$key->AMT_NONFARE.'";';
                $csvFile .='"'.$key->TGL_RK.'"';
                $csvFile .="\n";
            }

        return $csvFile;
    }



    public function generatePdf($tglTrx,  $merchant, $getDataRk, $getDataDetailRk){
        // logic generate csv
        $resultGenerate =[];
        $format ='pdf';
        $footerHtml= storage_path('app/ebor_generate/footer.html');
        $htmlRK = $this->rkHtml($tglTrx,  $merchant, $getDataRk );
        $htmlDetailRk = $this->detailRkHtml($tglTrx,  $merchant, $getDataDetailRk );
        $filenameRkHtml = $merchant->mid.'_file_rk_'.$tglTrx.'.html';
        $filenameDetailRkHtml =  $merchant->mid.'_file_detail_rk_'.$tglTrx.'.html';

        $filenameRkPdf = $merchant->mid.'_file_rk_'.$tglTrx.'.'.$format;
        $filenameDetailRkPdf = $merchant->mid.'_file_detail_rk_'.$tglTrx.'.'.$format;

        $footer ='<img src="'.public_path("asset/images/selendangbri.png").'" style="margin-left:5px" width="1000px" height="60px">';
        Storage::disk('ebor')->put('temp_pdf/'.$filenameRkHtml,  $htmlRK);
        Storage::disk('ebor')->put('temp_pdf/'. $filenameDetailRkHtml,  $htmlDetailRk);
        if (!Storage::disk('ebor')->exists('footer.html')) {
            Storage::disk('ebor')->put('footer.html',  $footer);
        }

        $basePath = storage_path('app/ebor_generate/');
        $filerkHtmlPath=  $basePath.'temp_pdf/'.$filenameRkHtml;
        $filerkPdfPath = $basePath.'temp_pdf/'. $filenameRkPdf;

        $fileDetailrkHtmlPath = $basePath.'temp_pdf/'. $filenameDetailRkHtml;
        $fileDetailrkPdfPath = $basePath.'temp_pdf/'.$filenameDetailRkPdf;

        if (Storage::disk('ebor')->exists('temp_pdf/'.$filenameRkHtml)){
            exec('wkhtmltopdf --enable-local-file-access -T 10 -R 10 -L 10 -B 35 -O landscape --footer-spacing 5 --footer-html '.$footerHtml.' --disable-smart-shrinking --page-size A4 '.$filerkHtmlPath.' '.$filerkPdfPath.' 2>&1');
            Storage::disk('ebor')->delete('temp_pdf/'.$filenameRkHtml);
        }
        if (Storage::disk('ebor')->exists('temp_pdf/'.$filenameDetailRkHtml)){
            exec('wkhtmltopdf --enable-local-file-access -T 10 -R 10 -L 10 -B 35 -O landscape --footer-spacing 5 --footer-html '.$footerHtml.' --disable-smart-shrinking --page-size A4 '.$fileDetailrkHtmlPath.' '.$fileDetailrkPdfPath.' 2>&1');
            Storage::disk('ebor')->delete('temp_pdf/'.$filenameDetailRkHtml);
        }

        $resultGenerate ['format'] =$format;
        $resultGenerate ['rk_file'] = 'failed create RK PDF';
        $resultGenerate ['detail_file'] = 'failed create Detail RK PDF';

        if (Storage::disk('ebor')->exists('temp_pdf/'.$filenameRkPdf)){
            $resultGenerate ['rk_file'] = $filenameRkPdf;
        }
        if ( Storage::disk('ebor')->exists('temp_pdf/'.$filenameDetailRkPdf)){
            $resultGenerate ['detail_file'] = $filenameDetailRkPdf;
        }


        return $resultGenerate;
    }

    public function detailRkHtml($tglTrx, $merchant, $getData){
        $htmlDetailRk ='';
        $htmlDetailRk .='<html> ';
        $htmlDetailRk .='<head> ';
        $htmlDetailRk .='	<meta http-equiv="content-type" content="text/html; charset=iso-8859-1" /> ';
        $htmlDetailRk .='	<title>REPORT DETAIL</title> ';
        $htmlDetailRk .='   <link rel="icon" href="'.public_path("asset/images/favicon.ico").'" type="image/ico">';
        $htmlDetailRk .='   <link rel="stylesheet" type="text/css" href="'.public_path("asset/styles/report.css").'">';
        $htmlDetailRk .='</head> ';
        $htmlDetailRk .='<body style="margin-left:0px;"> ';
        $htmlDetailRk .='<div id="cetak"> ';
        $htmlDetailRk .='<table width="750px" border="0" align="center"> ';
        $htmlDetailRk .=' ';
        $htmlDetailRk .='	<tr><!-- HEADER --> ';
        $htmlDetailRk .='		<td> ';
        $htmlDetailRk .='			<table> ';
        $htmlDetailRk .='				<tr> ';
        $htmlDetailRk .='					<td nowrap valign="middle"><h1>REPORT DETAIL TRANSAKSI EDC MERCHANT</h1></td> ';
        $htmlDetailRk .='                   <td style="text-align:right"><img src="'.public_path("asset/images/logobri.jpg").'"></td>';
        $htmlDetailRk .='				</tr> ';
        $htmlDetailRk .='			</table> ';
        $htmlDetailRk .='		</td> ';
        $htmlDetailRk .='	</tr> ';
        $htmlDetailRk .='	<tr><!-- Data Merchant dan Tgl --> ';
        $htmlDetailRk .='		<td> ';
        $htmlDetailRk .='			<table  style="font-size: 9px !important;"> ';
        $htmlDetailRk .='				<tr> ';
        $htmlDetailRk .='					<td> ';
        $htmlDetailRk .='						<fieldset> ';
        $htmlDetailRk .='						Yth.<br> ';
        $htmlDetailRk .=						$merchant->dba.' - '.$merchant->nama_merchant.'<br>';
        $htmlDetailRk .=						$merchant->mid.'<br><br> ';
        $htmlDetailRk .=						$merchant->alamat_merchant.'<br> ';
        $htmlDetailRk .=						$merchant->kelurahan.', '.$merchant->kecamatan.'<br>';
        $htmlDetailRk .=						$merchant->kabupaten.', '.$merchant->provinsi.'<br> ';
        $htmlDetailRk .=						$merchant->kodepos;
        $htmlDetailRk .='						</fieldset> ';
        $htmlDetailRk .='					</td> ';
        $htmlDetailRk .='					<td valign="top"> ';
        $htmlDetailRk .='						<table  style="font-size: 9px !important;"> ';
        $htmlDetailRk .='							<tr> ';
        $htmlDetailRk .='								<td align="right">Tanggal Laporan</td> ';
        $htmlDetailRk .='								<td>: '.now()->format('Y-m-d').'</td> ';
        $htmlDetailRk .='							</tr> ';
        $htmlDetailRk .='							<tr> ';
        $htmlDetailRk .='								<td align="right">Tanggal Transaksi Rek Koran</td> ';
        $htmlDetailRk .='								<td>: '.$tglTrx.'</td> ';
        $htmlDetailRk .='							</tr> ';
        $htmlDetailRk .='						</table> ';
        $htmlDetailRk .='					</td> ';
        $htmlDetailRk .='				</tr> ';
        $htmlDetailRk .='			</table> ';
        $htmlDetailRk .='		</td> ';
        $htmlDetailRk .='	</tr> ';
        $htmlDetailRk .='	<tr><!-- Keterangan Rekening --> ';
        $htmlDetailRk .='		<td> ';
        $htmlDetailRk .='			<table  style="font-size: 9px !important;"> ';
        $htmlDetailRk .='				<tr> ';
        $htmlDetailRk .='					<td>No Rekening</td> ';
        $htmlDetailRk .='					<td>: '.$merchant->no_rek.'</td> ';
        $htmlDetailRk .='					<td>Nama Produk</td> ';
        $htmlDetailRk .='					<td>: '.$this->get_produk($merchant->no_rek).'</td> ';
        $htmlDetailRk .='				</tr> ';
        $htmlDetailRk .='				<tr> ';
        $htmlDetailRk .='					<td>Nama Pemilik</td> ';
        $htmlDetailRk .='					<td>: '.$merchant->pemilik_rek.'</td> ';
        $htmlDetailRk .='					<td>Unit Kerja</td> ';
        $htmlDetailRk .='					<td>: '.$this->get_uker(substr($merchant->no_rek, 0, 4)) .'</td> ';
        $htmlDetailRk .='				</tr> ';
        $htmlDetailRk .='				<tr> ';
        $htmlDetailRk .='					<td>Valuta</td> ';
        $htmlDetailRk .='					<td>: IDR</td> ';
        $htmlDetailRk .='					<td>&nbsp;</td> ';
        $htmlDetailRk .='					<td>&nbsp;</td> ';
        $htmlDetailRk .='				</tr> ';
        $htmlDetailRk .='			</table> ';
        $htmlDetailRk .='		</td> ';
        $htmlDetailRk .='	</tr> ';
        $htmlDetailRk .='	<tr><!-- Data --> ';
        $htmlDetailRk .='		<td> ';
        $htmlDetailRk .='		<table border="1" style="width:100%;border-collapse: collapse; font-size: 7px !important;" class="tabledata" > ';
        $htmlDetailRk .='				<tr> ';
        $htmlDetailRk .='					<th>MID</th> ';
        $htmlDetailRk .='					<th>TID</th> ';
        $htmlDetailRk .='					<th>NAMA_MERCHANT</th> ';
        $htmlDetailRk .='					<th>TGL_TRX</th> ';
        $htmlDetailRk .='					<th>TGL_SETL</th> ';
        $htmlDetailRk .='					<th>CARD_NUMBER</th> ';
        $htmlDetailRk .='					<th>REMARK_RK</th> ';
        $htmlDetailRk .='					<th>AMT_SETL</th> ';
        $htmlDetailRk .='					<th>AMT_TRX</th> ';
        $htmlDetailRk .='					<th>RATE</th> ';
        $htmlDetailRk .='					<th>DISC_AMT</th> ';
        $htmlDetailRk .='					<th>NET_AMT</th> ';
        $htmlDetailRk .='					<th>TIPE</th> ';
        $htmlDetailRk .='					<th>PRINCIPLE</th> ';
        $htmlDetailRk .='					<th>ISSUER</th> ';
        $htmlDetailRk .='					<th style="word-wrap: break-word;">APRV_CODE/ REFF_NUM</th> ';
        $htmlDetailRk .='					<th>BATCH_NUM</th> ';
        $htmlDetailRk .='					<th>AMTNONFARE</th> ';
        $htmlDetailRk .='					<th>TGL_RK</th> ';
        $htmlDetailRk .='				</tr> ';
                        				$page_1 		= true;
                        				$rows 			= 1;
                        				$TOT_AMT_TRX 	= 0;
                                	    $TOT_NET_AMT 	= 0;
                                        $max_per_page1 	= 5;
                                        $max_per_page 	= 9;
                            			foreach ($getData as $key) {
                    					    $TOT_AMT_TRX += floatval($key->AMT_TRX);
                        				    $TOT_NET_AMT += floatval($key->NET_AMT);
                        				    if (strpos($key->MID, substr($merchant->mid, 3)) !== FALSE) {
                    					        $highlight = 'highlight';
                    					    } else { $highlight = ''; }
                        					if (($page_1 && $rows < $max_per_page1) || (!$page_1 && $rows < $max_per_page)) {
        $htmlDetailRk .='				<tr> ';
        $htmlDetailRk .='					<td class="'.$highlight.'">'.$key->MID.'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'">'.$key->TID.'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'" style="word-wrap: break-word;">'.preg_replace('/[^0-9a-zA-Z_\s]/', '', $key->NAMA_MERCHANT).'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'" nowrap>'.$key->TGL_TRX.'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'" nowrap>'.$key->TGL_SETL.'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'">'.$key->CARD_NUMBER.'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'" style="word-wrap: break-word;">'.$key->REMARK_RK.'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'" style="text-align:right" nowrap>'.number_format($key->AMT_SETL, 0, '.', ',').'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'" style="text-align:right" nowrap>'.number_format($key->AMT_TRX, 0, '.', ',').'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'" style="text-align:right" nowrap>'.$key->RATE.'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'" style="text-align:right" nowrap>'.number_format($key->DISC_AMT, 0, '.', ',').'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'" style="text-align:right" nowrap>'.number_format($key->NET_AMT, 0, '.', ',').'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'">'.$key->TIPE.'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'">'.$key->PRINCIPLE.'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'">'.$key->ISSUER.'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'">'.$key->APRV_CODE_OR_REFF_NO.'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'">'.$key->BATCH_NUM.'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'" style="text-align:right" nowrap>'.number_format($key->AMT_NONFARE, 0, '.', ',').'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'" nowrap>'.$key->TGL_RK.'</td> ';
        $htmlDetailRk .='				</tr>	 ';
                                                } else {
        $htmlDetailRk .='				<tr> ';
        $htmlDetailRk .='					<td class="'.$highlight.'">'.$key->MID.'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'">'.$key->TID.'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'" style="word-wrap: break-word;">'.preg_replace('/[^0-9a-zA-Z_\s]/', '', $key->NAMA_MERCHANT).'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'" nowrap>'.$key->TGL_TRX.'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'" nowrap>'.$key->TGL_SETL.'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'">'.$key->CARD_NUMBER.'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'" style="word-wrap: break-word;">'.$key->REMARK_RK.'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'" style="text-align:right" nowrap>'.number_format($key->AMT_SETL, 0, '.', ',').'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'" style="text-align:right" nowrap>'.number_format($key->AMT_TRX, 0, '.', ',').'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'" style="text-align:right" nowrap>'.$key->RATE.'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'" style="text-align:right" nowrap>'.number_format($key->DISC_AMT, 0, '.', ',').'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'" style="text-align:right" nowrap>'.number_format($key->NET_AMT, 0, '.', ',').'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'">'.$key->TIPE.'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'">'.$key->PRINCIPLE.'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'">'.$key->ISSUER.'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'">'.$key->APRV_CODE_OR_REFF_NO.'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'">'.$key->BATCH_NUM.'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'" style="text-align:right" nowrap>'.number_format($key->AMT_NONFARE, 0, '.', ',').'</td> ';
        $htmlDetailRk .='					<td class="'.$highlight.'" nowrap>'.$key->TGL_RK.'</td> ';
        $htmlDetailRk .='				</tr>	 ';
        	                                if ($page_1) $page_1 = FALSE;
                                                $rows = 0;
        $htmlDetailRk .='			</table> ';
        $htmlDetailRk .='			<div style="page-break-after: always;"></div> ';
        $htmlDetailRk .='			<table border="1" style="width:100%;border-collapse: collapse; font-size: 7px !important;" class="tabledata" > ';
        $htmlDetailRk .='				<tr> ';
        $htmlDetailRk .='				<th>MID</th> ';
        $htmlDetailRk .='					<th>TID</th> ';
        $htmlDetailRk .='					<th>NAMA_MERCHANT</th> ';
        $htmlDetailRk .='					<th>TGL_TRX</th> ';
        $htmlDetailRk .='					<th>TGL_SETL</th> ';
        $htmlDetailRk .='					<th>CARD_NUMBER</th> ';
        $htmlDetailRk .='					<th>REMARK_RK</th> ';
        $htmlDetailRk .='					<th>AMT_SETL</th> ';
        $htmlDetailRk .='					<th>AMT_TRX</th> ';
        $htmlDetailRk .='					<th>RATE</th> ';
        $htmlDetailRk .='					<th>DISC_AMT</th> ';
        $htmlDetailRk .='					<th>NET_AMT</th> ';
        $htmlDetailRk .='					<th>TIPE</th> ';
        $htmlDetailRk .='					<th>PRINCIPLE</th> ';
        $htmlDetailRk .='					<th>ISSUER</th> ';
        $htmlDetailRk .='					<th style="word-wrap: break-word;">APRV_CODE/ REFF_NUM</th> ';
        $htmlDetailRk .='					<th>BATCH_NUM</th> ';
        $htmlDetailRk .='					<th>AMTNONFARE</th> ';
        $htmlDetailRk .='					<th>TGL_RK</th> ';
        $htmlDetailRk .='				</tr> ';
                                            } $rows++; }
        $htmlDetailRk .='			</table> ';
        $htmlDetailRk .='		</td> ';
        $htmlDetailRk .='	</tr> ';
        $htmlDetailRk .='	<tr><!-- Closing --> ';
        $htmlDetailRk .='		<td> ';
        $htmlDetailRk .='			<table > ';
        $htmlDetailRk .='				<tr> ';
        $htmlDetailRk .='					<ul style="list-style-type: circle;font-size: 9px !important;"> ';
        $htmlDetailRk .='						<li>Apabila terdapat perbedaan dengan catatan Saudara, harap menghubungi hotline merchant BRI (021-500274) selambat-lambatnya 3 hari sejak diterimanya Email Transaksi ini.</li> ';
        $htmlDetailRk .='						<li>Bank BRI berhak untuk mendebet kembali rekening apabila terjadi kelebihan pembayaran</li> ';
        $htmlDetailRk .='						<li>Baris dengan warna merah: transaksi yang terjadi di Merchant Anda</li> ';
        $htmlDetailRk .='						<li>Merchant dilarang membebankan biaya tambahan (surcharge) kepada cardhpolder atas transaksi kartu yang dilakukan sebagaimana dimaksud dalam poin VII penyelenggaraan kegiatan APMK huruf E tentang kerjasama Acquiring dengan pedagang atau pihak lain dalam surat Edaran Bank Indonesia Nomor 11/10/DSAP tanggal 13 April 2009 perihal penyelenggaraan kegiatan alat pembayaran dengan menggunakan kartu</li> ';
        $htmlDetailRk .='						<li>Untuk meningkatkan keamanan dalam bertransaksi menggunakan kartu ATM/Debit Domestik, sesuai Peraturan Bank Indonesia, terhitung mulai tanggal 1 Juli 2017 transaksi menggunakan kartu ATM/Debit Domestik pada EDC di Indonesia (domestik) wajib menggunakan PIN dan tidak diperbolehkan menggunakan tanda tangan.</li> ';
        $htmlDetailRk .='						<li>Sesuai dengan adanya larangan dari Bank Indonesia terkait penggesekan ganda pada transaksi non tunai, maka kepada seluruh Merchant BRI dilarang melakukan penggesekan ganda (double swipe) di mesin kasir dan mesin EDC BRI, penggesekan kartu hanya diperkenankan digesek di mesin EDC BRI saja.</li> ';
        $htmlDetailRk .='					</ul> ';
        $htmlDetailRk .='				</tr>				 ';
        $htmlDetailRk .='			</table> ';
        $htmlDetailRk .='		</td> ';
        $htmlDetailRk .='	</tr>	 ';
        $htmlDetailRk .='</table> ';
        $htmlDetailRk .='</div> ';
        $htmlDetailRk .='</body> ';
        $htmlDetailRk .='</html> ';

        return  $htmlDetailRk;

    }


    public function rkHtml($tglTrx, $merchant, $getData){
            $htmlRK ='';
            $htmlRK .='<html>';
            $htmlRK .='<head>';
                $htmlRK .='<meta http-equiv="content-type" content="text/html; charset=iso-8859-1" />';
                $htmlRK .='<title> REPORT RK </title>';
                $htmlRK .='<link rel="icon" href="'.public_path("asset/images/    favicon.ico").'">';
                $htmlRK .='<link rel="stylesheet" type="text/css" href="'.public_path("asset/styles/report.css").'">';
            $htmlRK .='</head>';
            $htmlRK .='<body>';
                $htmlRK .='<div id="cetak">';
                    $htmlRK .='<table width="740px" style="border:none;" cellspacing="0" cellpadding="0" class="tablemain">';
                        $htmlRK .='<tr>';
                            $htmlRK .='<td>';
                                $htmlRK .='<table>';
                                    $htmlRK .='<tr>';
                                        $htmlRK .='<td nowrap valign="middle"><h1>PEMBAYARAN TRANSAKSI EDC MERCHANT</h1></td>';
                                        $htmlRK .='<td style="text-align:right"><img src="'.public_path("asset/images/logobri.jpg").'"></td>';
                                    $htmlRK .='</tr>';
                                $htmlRK .='</table>';
                                $htmlRK .='<td>';
                        $htmlRK .='</tr>';
                        $htmlRK .='<tr><!-- Data Merchant dan Tgl -->';
                            $htmlRK .='<td>';
                                $htmlRK .='<table>';
                                    $htmlRK .='<tr>';
                                        $htmlRK .='<td>';
                                            $htmlRK .='<fieldset>';
                                            $htmlRK .='Yth.<br>';
                                            $htmlRK .=$merchant->dba.'<br>';
                                            $htmlRK .=$merchant->mid.'<br><br>';
                                            $htmlRK .=$merchant->alamat_merchant.'<br>';
                                            $htmlRK .=$merchant->kelurahan.', '.$merchant->kecamatan.'<br>';
                                            $htmlRK .=$merchant->kabupaten.', '.$merchant->provinsi.'<br>';
                                            $htmlRK .=$merchant->kodepos;
                                            $htmlRK .='</fieldset>';
                                        $htmlRK .='</td>';
                                        $htmlRK .='<td valign="top">';
                                            $htmlRK .='<table>';
                                                $htmlRK .='<tr>';
                                                    $htmlRK .='<td align="right">Tanggal Laporan</td>';
                                                    $htmlRK .='<td>: '.now()->format('Y-m-d').'</td>';
                                                $htmlRK .='</tr>';
                                                $htmlRK .='<tr>';
                                                    $htmlRK .='<td align="right">Tanggal Transaksi Rek Koran</td>';
                                                    $htmlRK .='<td>: '.$tglTrx.'</td>';
                                                $htmlRK .='</tr>';
                                            $htmlRK .='</table>';
                                        $htmlRK .='</td>';
                                    $htmlRK .='</tr>';
                                $htmlRK .='</table>';
                            $htmlRK .='</td>';
                        $htmlRK .='</tr>';
                        $htmlRK .='<tr><!-- Keterangan Rekening -->';
                            $htmlRK .='<td>';
                                $htmlRK .='<table>';
                                    $htmlRK .='<tr>';
                                        $htmlRK .='<td>No Rekening</td>';
                                        $htmlRK .='<td>: '.$merchant->no_rek.'</td>';
                                        $htmlRK .='<td>Nama Produk</td>';
                                        $htmlRK .='<td>: '.$this->get_produk($merchant->no_rek).'</td>';
                                    $htmlRK .='</tr>';
                                    $htmlRK .='<tr>';
                                        $htmlRK .='<td>Nama Pemilik</td>';
                                        $htmlRK .='<td>: '.$merchant->pemilik_rek.'</td>';
                                        $htmlRK .='<td>Unit Kerja</td>';
                                        $htmlRK .='<td>: '.$this->get_uker(substr($merchant->no_rek, 0, 4)).'</td>';
                                    $htmlRK .='</tr>';
                                    $htmlRK .='<tr>';
                                        $htmlRK .='<td>Valuta</td>';
                                        $htmlRK .='<td>: IDR</td>';
                                        $htmlRK .='<td>&nbsp;</td>';
                                        $htmlRK .='<td>&nbsp;</td>';
                                    $htmlRK .='</tr>';
                                $htmlRK .='</table>';
                            $htmlRK .='</td>';
                        $htmlRK .='</tr>';
                        $htmlRK .='<tr><!-- Data -->';
                            $htmlRK .='<td>';
                                $htmlRK .='<table border="1" style="border-collapse: collapse" class="tabledata">';
                                    $htmlRK .='<tr style="height:19px">';
                                        $htmlRK .='<th>DATETIMES</th>';
                                        $htmlRK .='<th>AMOUNT</th>';
                                        $htmlRK .='<th>REMARK</th>';
                                    $htmlRK .='</tr>';
                                    $page_1 		= TRUE;
                                    $rows 			= 1;
                                    $max_per_page1 	= 8;
                                    $max_per_page 	= 28;
                                    foreach ($getData as $key) {
                                        $highlight = '';
                                        if (strpos($key->REMARK, substr($merchant->mid, 3)) !== FALSE) {
                                            $highlight = 'highlight';
                                        } elseif (strpos($key->REMARK, "EMONEY") !== FALSE) {
                                            if (strpos($key->MID, substr($merchant->mid, 3)) !== FALSE) {
                                                $highlight = 'highlight';
                                            }
                                        }
                                        if (($page_1 && $rows < $max_per_page1) || (!$page_1 && $rows < $max_per_page)) {
                                            $htmlRK .='<tr>';
                                                $htmlRK .='<td class="">'.$key->TRXDATE.'</td>';
                                                $htmlRK .='<td class="" style="text-align:right" nowrap>'.$key->TRXDATE.'</td>';
                                                $htmlRK .='<td class="">'.$key->REMARK.' </td>';
                                            $htmlRK .='</tr>';
                                        }else{
                                            $htmlRK .='<tr>';
                                                $htmlRK .='<td class="">'.$key->TRXDATE.'</td>';
                                                $htmlRK .='<td class="" style="text-align:right" nowrap>'.$key->TRXDATE.'</td>';
                                                $htmlRK .='<td class="">'.$key->REMARK.' </td>';
                                            $htmlRK .='</tr>';
                                            if ($page_1) {
                                                $page_1 = FALSE;
                                            }
                                            $rows = 0;
                                            $htmlRK .='</table>';
                                            $htmlRK .='<div style="page-break-after: always;"></div>';
                                            $htmlRK .='<table border="1" style="border-collapse: collapse" class="tabledata">';
                                                $htmlRK .='<tr style="height:19px">';
                                                    $htmlRK .='<th>DATETIMEX</th>';
                                                    $htmlRK .='<th>AMOUNT</th>';
                                                    $htmlRK .='<th>REMARK</th>';
                                                $htmlRK .='</tr>';
                                        }
                                        $rows++;
                                    }
                            $htmlRK .='</table>';
                            $htmlRK .='</td>';
                        $htmlRK .='</tr>';
                        $htmlRK .='<tr><!-- Closing -->';
                            $htmlRK .='<td colspan="3">';
                                $htmlRK .='<table style="font-size:10px">';
                                    $htmlRK .='<tr>';
                                        $htmlRK .='<td>';
                                            $htmlRK .='<ul style="list-style-type: circle;">';
                                                $htmlRK .='<li>Keterangan Remark: <br>';
                                                    $htmlRK .='<table>';
                                                        $htmlRK .='<tr><td>- CC</td><td>: Pembayaran Settlement Transaksi Kartu Kredit BRI dan Kartu Bank Lain</td></tr>';
                                                        $htmlRK .='<tr><td>- SETD</td><td>: Pembayaran Settlement Transaksi Kartu Debit BRI</td></tr>';
                                                        $htmlRK .='<tr><td>- EMONEYSETTLE</td><td>: Pembayaran Settlement Transaksi BRIZZI</td></tr>';
                                                        $htmlRK .='<tr><td>- QRISONUS</td><td>: Pembayaran Settlement Transaksi QRIS melalui BRIMO</td></tr>';
                                                        $htmlRK .='<tr><td>- QRISOFFUS</td><td>: Pembayaran Settlement Transaksi QRIS selain BRIMO</td></tr>';
                                                        $htmlRK .='<tr><td>- JCB</td><td>: Pembayaran Settlement Transaksi Kartu Kredit Principle JCB</td></tr>';
                                                    $htmlRK .='</table>';
                                                $htmlRK .='</li>';
                                                $htmlRK .='<li>Apabila terdapat perbedaan dengan catatan Saudara, harap menghubungi hotline merchant BRI (021-500274) selambat-lambatnya 3 hari sejak diterimanya Email Transaksi ini.</li>';
                                                $htmlRK .='<li>Bank BRI berhak untuk mendebet kembali rekening apabila terjadi kelebihan pembayaran</li>';
                                                $htmlRK .='<li>Baris dengan warna merah: transaksi yang terjadi di Merchant Anda</li>';
                                                $htmlRK .='<li>Merchant dilarang membebankan biaya tambahan (surcharge) kepada cardhpolder atas transaksi kartu yang dilakukan sebagaimana dimaksud dalam poin VII penyelenggaraan kegiatan APMK huruf E tentang kerjasama Acquiring dengan pedagang atau pihak lain dalam surat Edaran Bank Indonesia Nomor 11/10/DSAP tanggal 13 April 2009 perihal penyelenggaraan kegiatan alat pembayaran dengan menggunakan kartu</li>';
                                                $htmlRK .='<li>Untuk meningkatkan keamanan dalam bertransaksi menggunakan kartu ATM/Debit Domestik, sesuai Peraturan Bank Indonesia, terhitung mulai tanggal 1 Juli 2017 transaksi menggunakan kartu ATM/Debit Domestik pada EDC di Indonesia (domestik) wajib menggunakan PIN dan tidak diperbolehkan menggunakan tanda tangan.</li>';
                                                $htmlRK .='<li>Sesuai dengan adanya larangan dari Bank Indonesia terkait penggesekan ganda pada transaksi non tunai, maka kepada seluruh Merchant BRI dilarang melakukan penggesekan ganda (double swipe) di mesin kasir dan mesin EDC BRI, penggesekan kartu hanya diperkenankan digesek di mesin EDC BRI saja.</li>';
                                            $htmlRK .='</ul>';
                                        $htmlRK .='</td>';
                                    $htmlRK .='</tr>';
                                $htmlRK .='</table>';
                            $htmlRK .='</td>';
                        $htmlRK .='</tr>';
                    $htmlRK .='</table>';
                $htmlRK .='</div>';
            $htmlRK .="</body>";
        $htmlRK .="</html>";
        return  $htmlRK;

    }
    public function get_produk($no_rek){

        $kode = substr($no_rek, 12, 2);
        if ($kode == '30')
            return "GIRO BRI";
        if ($kode == '50')
            return "BRITAMA";
        if ($kode == '51')
            return "TABUNGAN HAJI BRI";
        if ($kode == '52')
            return "TABUNGANKU BRI";
        if ($kode == '53')
            return "SIMPEDES";
        if ($kode == '56')
            return "BRITAMA BISNIS";
        if ($kode == '99')
            return "REKENING TITIPAN";
        return "---";
    }

    public function get_uker($kode){
        if ($kode=='0000')
            return "KKD";
        else {
            $get =  DB::table('DWH_BRANCH')
            ->select('BRDESC')
            ->where('BRANCH',$kode )
            ->get();
            if (count($get) > 0){
            	return $get[0]->BRDESC;
            }else {
                $get =  DB::table('RO_BRANCH')
                ->select('nama_sub_branch')
                ->where('kode_sub_branch',$kode )
                ->get();
            	if (count($get) > 0)
                    return $get[0]->nama_sub_branch;
            	else
            		return "---";
            }
        }
    }


}
