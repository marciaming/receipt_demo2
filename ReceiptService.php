<?php
/**
 * Created by PhpStorm.
 * User: ming
 * Date: 2018/3/8
 * Time: 上午11:58
 */

/***隱蔽某些code***/

class ReceiptService
{
    const ReceiptListUrl = 'receipt/ajax_action.php?form=receiptList';
    const ReceiptDetailUrl = 'receipt/ajax_action.php?form=receiptDetail';
    const ReceiptLogUrl = 'receipt/ajax_action.php?form=receiptLog';
    const ReceiptVoidUrl = 'receipt/ajax_action.php?form=voidReceipt';

    private /***隱蔽某些code***/;
    private $ReceiptDAOSQL;
    private $CustomerService;

    public $start;
    public $per_page;
    public $row_name;
    public $search;

    function __construct()
    {
        /***隱蔽某些code***/

        $medoo = new Medoo();
        $pdo = $medoo->pdo;
        $this->ReceiptDAOSQL = new ReceiptDAOSQL($pdo);
        $this->CustomerService = new CustomerService($pdo);
    }


    /**
     * 綠界電子發票
     */

    function createReceipt($ReceiptSetting, $customer, $orderId)
    {

        $orderMain = /***隱蔽某些code***/

        if($orderMain->receipt_number){
            return array(
                'result'      => false,
                'errorMsg'    => '已開過發票',
                'RtnData'     => '',
                'orderNumber' => $orderMain->ordernumber
            );
            exit;
        }

        $orderList = /***隱蔽某些code***/
        $total = /***隱蔽某些code***/
        $total = (int)$total - (int)$orderMain->coupon - (int)$orderMain->bonus_used;

        $Donation = $this->checkLovecode($orderMain);
        $Identifier = $this->checkIdentifier($orderMain);

        $Donation?$Identifier=false:$Identifier=true;

        if($this->check($ReceiptSetting,$orderMain) != ''){
            return array(
                'result'      => false,
                'errorMsg'    => $this->check($ReceiptSetting,$orderMain),
                'RtnData'     => '',
                'orderNumber' => $orderMain->ordernumber
            );
            exit;
        }

        try
        {
            $sMsg = '' ;

            include_once('Ecpay_Invoice.php');
            $ecpay_invoice = new EcpayInvoice;

            $ecpay_invoice->Invoice_Method 		= 'INVOICE' ;
            $ecpay_invoice->Invoice_Url 		= 'https://einvoice-stage.ecpay.com.tw/Invoice/Issue' ;
            $ecpay_invoice->MerchantID 			= $customer['allpay_storeid'];
            $ecpay_invoice->HashKey 			= $customer['allpay_key'];
            $ecpay_invoice->HashIV 				= $customer['allpay_iv'];

            if(getenv("APP_ENV") == Main::APP_ENV_PRODUCTION  ){
                $ecpay_invoice->Invoice_Url = "https://einvoice.ecpay.com.tw/Invoice/Issue";
            }

            if($ReceiptSetting->products_list_type){
                array_push($ecpay_invoice->Send['Items'], array('ItemName' => $ReceiptSetting->products_specified_name, 'ItemCount' => 1, 'ItemWord' => '批', 'ItemPrice' => $total, 'ItemTaxType' => 1, 'ItemAmount' => $total, 'ItemRemark' => ' ')) ;
            }else{
                foreach ($orderList as $row){
                    array_push($ecpay_invoice->Send['Items'], array('ItemName' => $row->p_name, 'ItemCount' => $row->buy_num, 'ItemWord' => '批', 'ItemPrice' => $row->price, 'ItemTaxType' => 1, 'ItemAmount' => $row->price, 'ItemRemark' => ' ')) ;
                }
            }

            if($orderMain->coupon != 0){
                array_push($ecpay_invoice->Send['Items'], array('ItemName' => 優惠卷折扣, 'ItemCount' => 1, 'ItemWord' => '筆', 'ItemPrice' => -(int)$orderMain->coupon, 'ItemTaxType' => 1, 'ItemAmount' => -(int)$orderMain->coupon, 'ItemRemark' => ' ')) ;
            }
            if($orderMain->bonus_used != 0){
                array_push($ecpay_invoice->Send['Items'], array('ItemName' => 點數折扣, 'ItemCount' => 1, 'ItemWord' => '筆', 'ItemPrice' => -(int)$orderMain->bonus_used, 'ItemTaxType' => 1, 'ItemAmount' => -(int)$orderMain->bonus_used, 'ItemRemark' => ' ')) ;
            }

            $RelateNumber = $orderMain->ordernumber. mktime();  //訂單編號＋timestamp

            $ecpay_invoice->Send['RelateNumber'] 			= $RelateNumber;
            $ecpay_invoice->Send['CustomerID'] 			    = '';
            $ecpay_invoice->Send['CustomerIdentifier'] 		= ($Identifier)?$orderMain->invoice_id:'';
            $ecpay_invoice->Send['CustomerName'] 			= ($Identifier)?$orderMain->invoice_title:'';
            $ecpay_invoice->Send['CustomerAddr'] 			= (!$Donation)?$orderMain->invoice_address:'';
            $ecpay_invoice->Send['CustomerPhone'] 			= $orderMain->mobile;
            $ecpay_invoice->Send['CustomerEmail'] 			= $orderMain->email;
            $ecpay_invoice->Send['ClearanceMark'] 			= ($ReceiptSetting->tax_type == 2)?'2':'';
            $ecpay_invoice->Send['Print'] 				    = ($orderMain->invoice_type == '二聯'|| !$Identifier)?0:1;
            $ecpay_invoice->Send['Donation'] 			    = $Donation?1:2;

            $ecpay_invoice->Send['LoveCode'] 			    = $Donation?$orderMain->donate_lovecode:'';
            $ecpay_invoice->Send['CarruerType'] 			= '';
            $ecpay_invoice->Send['CarruerNum'] 			    = '';
            $ecpay_invoice->Send['TaxType'] 			    = (int)$ReceiptSetting->tax_type;
            $ecpay_invoice->Send['SalesAmount'] 			= $total;
            $ecpay_invoice->Send['InvoiceRemark'] 			= '';
            $ecpay_invoice->Send['InvType'] 			    = $ReceiptSetting->receipt_code;
            $ecpay_invoice->Send['vat'] 				    = 1;

            //寫入log
            $ReceiptLogDAO = new ReceiptLogDAO();
            $ReceiptLogDAO->customer_id    = $customer['id'];
            $ReceiptLogDAO->receipt_from   = $ReceiptSetting->receipt_from;
            $ReceiptLogDAO->order_id       = $orderId;
            $ReceiptLogDAO->type           = 'receipt';
            $ReceiptLogDAO->log            = json_encode($ecpay_invoice->Send);
            $ReceiptLogDAO->status         = 1;
            $ReceiptLogDAO->insert();

            //寫入發票列表
            $ReceiptDAO = new ReceiptDAO();
            $ReceiptDAO->order_id       = $orderId;
            $ReceiptDAO->customer_id    = $customer['id'];
            $ReceiptDAO->receipt_from   = $ReceiptSetting->receipt_from;
            $ReceiptDAO->create_type    = $ReceiptSetting->create_type;
            $ReceiptDAO->status         = 0;
            $ReceiptDAO->total          = $total;
            $ReceiptDAO->create_from    = $_SESSION['gmail'];

            if($ReceiptDAO->checkExist()){
                $ReceiptDAO->updateWhenReceiptReSent();
            }else{
                $ReceiptDAO->insert();
            }

            $ReceiptDAO->getReceiptByOrderId();

            $aReturn_Info = $ecpay_invoice->Check_Out();

            //紀錄回傳的json
            $ReceiptLogDAO->log    = json_encode($aReturn_Info);
            $ReceiptLogDAO->status = 2;
            $ReceiptLogDAO->insert();

            if($aReturn_Info['RtnMsg'] == '開立發票成功'){
                $ReceiptDAO->status              = 1;
                $ReceiptDAO->receipt_number      = $aReturn_Info['InvoiceNumber'];
                $ReceiptDAO->create_receipt_at   = $aReturn_Info['InvoiceDate'];
                $ReceiptDAO->relate_number       = $RelateNumber;
                $ReceiptDAO->updateWhenCreateSuccessById();

                //更新 order_main發票號碼
                $this->/***隱蔽某些code***/->updateReceiptNumber($orderId,$aReturn_Info['InvoiceNumber']);

                //notification
                if($ReceiptSetting->notification_status == '1'){
                    $notificationResult = $this->sentNotification($customer,$ReceiptSetting,$orderMain,$aReturn_Info['InvoiceNumber'],'C');
                }

                if($ReceiptSetting->notification_status == '1' && $ReceiptSetting->notification_to != 'C'){

                    $sentCustomerNotification = false;

                    switch ($ReceiptSetting->notification_type){
                        case 'S':
                            ($ReceiptSetting->customer_cell_phone != '')?$sentCustomerNotification = true:'';
                            break;
                        case 'E':
                            ($ReceiptSetting->customer_email != '')?$sentCustomerNotification = true:'';
                            break;
                        case 'A':
                            ($ReceiptSetting->customer_cell_phone != '' && $ReceiptSetting->customer_email != '')?$sentCustomerNotification = true:'';
                            break;
                    }

                    if($sentCustomerNotification){
                        $notificationResult = $this->sentNotification($customer,$ReceiptSetting,$orderMain,$aReturn_Info['InvoiceNumber'],'M');
                    }

                }

            }else{
                return array(
                    'result'      => false,
                    'errorMsg'    => $aReturn_Info[0].' 您的綠界帳號可能錯誤或沒有開啟相關功能，請再次確認相關設定。',
                    'RtnData'     => '',
                    'orderNumber' => $orderMain->ordernumber);
                exit;
            }

        }
        catch (Exception $e)
        {
            // 例外錯誤處理。
            $sMsg = $e->getMessage();
        }

        return array(
            'result'        => ($aReturn_Info['RtnMsg'] == '開立發票成功')?true:false,
            'errorMsg'      => '',
            'RtnData'       => $aReturn_Info,
            'orderNumber'   => $orderMain->orderNumber
        );
    }

    /**
     * 資料驗證
     */

    public function check($receiptSetting,$orderMain)
    {
        $errorMsg = '';

        if($orderMain->invoice_type == ''){

            $errorMsg .= '消費者發票開立類型欄位為空或未選擇開立發票/';

        }

        //訂單編號是否過長 重複發送的話要另外處理
//        if(strlen($orderMain->ordernumber) > 15){
//            $errorMsg .= '訂單編號過長/';
//        }

        $Donation = $this->checkLovecode($orderMain);

        if($Donation){
            $Identifier = false;
        }else{
            $Identifier = true;
        }

        //如果使用統編
        if($orderMain->invoice_type == '三聯' && $Identifier){
            if($orderMain->invoice_id == ''){
                $errorMsg .= '消費者未填寫統編/';
            }

            if($orderMain->invoice_title == ''){
                $errorMsg .= '消費者未填寫買受人/';
            }

            if($orderMain->invoice_address == ''){
                $errorMsg .= '消費者地址不得為空/';
            }
        }

        //通知資料驗證
        if($receiptSetting->notification_status && $receiptSetting->notification_to != 'M'){
            switch ($receiptSetting->notification_type){
                case 'A':

                    if(!$this->checkEmail($orderMain->email) ){
                        $errorMsg .= '消費者E-mail格式錯誤/';
                    }

                    if(!$this->checkPhone($orderMain->mobile) ){
                        $errorMsg .= '消費者手機號碼格式錯誤/';
                    }

                    break;
                case 'S':

                    if(!$this->checkPhone($orderMain->mobile) ){
                        $errorMsg .= '消費者手機號碼格式錯誤/';
                    }
                    break;
                case 'E':

                    if(!$this->checkEmail($orderMain->email) ){
                        $errorMsg .= '消費者E-mail格式錯誤/';
                    }
                    break;
            }

        }else{
            if($orderMain->email != '' && $orderMain->mobile != '' ){
                if(!$this->checkEmail($orderMain->email)){
                    $errorMsg .= '消費者E-mail格式錯誤/';
                }

                if(!$this->checkPhone($orderMain->mobile)){
                    $errorMsg .= '消費者手機號碼格式錯誤/';
                }
            }elseif($orderMain->email != ''){
                if(!$this->checkEmail($orderMain->email) && $orderMain->email != ''){
                    $errorMsg .= '消費者E-mail格式錯誤/';
                }
            }elseif($orderMain->mobile != ''){
                if(!$this->checkPhone($orderMain->mobile) && $orderMain->mobile == ''){
                    $errorMsg .= '消費者手機號碼格式錯誤/';
                }
            }else{
                $errorMsg .= '消費者手機號碼與E-mail不得同時為空/';
            }
        }

        return $errorMsg;

    }

    /**
     * 確認統編
     */

    public function checkIdentifier($orderMain)
    {
        if($orderMain->invoice_type == '三聯' && $orderMain->invoice_id != '' && $orderMain->invoice_title != '' && $orderMain->donate_lovecode ==''){
            return true;
        }else{
            return false;
        }
    }

    /**
     * 確認消費者資料 手機
     */

    public function checkPhone($phone)
    {
        return preg_match("/^[09]{2}[0-9]{8}$/", $phone);
    }

    /**
     * 確認消費者資料 mail
     */

    public function checkEmail($email)
    {
        return preg_match("/^[^0-9][A-z0-9_]+([.][A-z0-9_]+)*[@][A-z0-9_]+([.][A-z0-9_]+)*[.][A-z]{2,4}$/i", $email);
    }

    /**
     * 三聯愛心碼就無效
     */

    public function checkLovecode($orderMain)
    {
        if( $orderMain->donate_lovecode != ''){
            return true;
        }else{
            return false;
        }
    }

    /**
     * 取得訂單列表
     */

    public function getReceiptsForDataTable()
    {

        $receipts = $this->ReceiptDAOSQL->getReceiptByCustomerId($_SESSION['customer_id']);
        $receiptListData = $this->ReceiptDAOSQL->getReceiptByCustomerIdForDataTable($_SESSION['customer_id'],$this->start,$this->per_page,$this->row_name,$this->search);

        $receiptsArray = array();
        foreach ($receiptListData as $row){
            $ReceiptDAO = new ReceiptDAO;
            $ReceiptDAO->getReceiptByData($row);
            array_push($receiptsArray,$ReceiptDAO);
        }

        return  array(
            'rows'  => $receiptsArray,
            'total' => count($receipts)
        );

    }

    /**
     * 檢視發票資料
     */

    public function getReceiptDetail($customer, $relateNumber)
    {

        include_once('Ecpay_Invoice.php');
        $ecpay_invoice = new EcpayInvoice;

        $ecpay_invoice->Invoice_Method 		= 'INVOICE_SEARCH' ;
        $ecpay_invoice->Invoice_Url 		= 'https://einvoice-stage.ecpay.com.tw/Query/Issue' ;
        $ecpay_invoice->MerchantID 			= $customer->allpay_storeid;
        $ecpay_invoice->HashKey 			= $customer->allpay_key;
        $ecpay_invoice->HashIV 				= $customer->allpay_iv;

        if(getenv("APP_ENV") == Main::APP_ENV_PRODUCTION  ){
            $ecpay_invoice->Invoice_Url = "https://einvoice.ecpay.com.tw/Query/Issue";
        }

        $ecpay_invoice->Send['RelateNumber'] = $relateNumber;
        $aReturn_Info = $ecpay_invoice->Check_Out();

        return $aReturn_Info;
    }

    public function getReceiptLog($orderId){
        $ReceiptDAO = new ReceiptDAO;
        $ReceiptDAO->order_id = $orderId;

        $ReceiptLog = $ReceiptDAO->getReceiptLog();

        return $ReceiptLog;
    }

    /**
     * 作廢發票
     */

    public function voidReceipt($customer, $receiptId, $reason)
    {
        $ReceiptDAO = new ReceiptDAO();
        $ReceiptDAO->id       = $receiptId;
        $receiptData = $ReceiptDAO->getReceiptById();

        include_once('Ecpay_Invoice.php');
        $ecpay_invoice = new EcpayInvoice;

        $ecpay_invoice->Invoice_Method 		= 'INVOICE_VOID' ;
        $ecpay_invoice->Invoice_Url 		= 'https://einvoice-stage.ecpay.com.tw/Invoice/IssueInvalid' ;
        $ecpay_invoice->MerchantID 			= $customer->allpay_storeid;
        $ecpay_invoice->HashKey 			= $customer->allpay_key;
        $ecpay_invoice->HashIV 				= $customer->allpay_iv;

        if(getenv("APP_ENV") == Main::APP_ENV_PRODUCTION  ){
            $ecpay_invoice->Invoice_Url = "https://einvoice.ecpay.com.tw/Invoice/IssueInvalid";
        }

        $ecpay_invoice->Send['InvoiceNumber'] = $receiptData->receipt_number;
        $ecpay_invoice->Send['Reason'] 		  = $reason;

        //寫入log
        $ReceiptLogDAO = new ReceiptLogDAO();
        $ReceiptLogDAO->customer_id    = $_SESSION['customer_id'];
        $ReceiptLogDAO->receipt_from   = $receiptData->receipt_from;
        $ReceiptLogDAO->order_id       = $receiptData->order_id;
        $ReceiptLogDAO->type           = 'void-receipt';
        $ReceiptLogDAO->log            = json_encode($ecpay_invoice->Send);
        $ReceiptLogDAO->status         = 1;
        $ReceiptLogDAO->insert();

        $aReturn_Info = $ecpay_invoice->Check_Out();

        //紀錄回傳的json
        $ReceiptLogDAO->log = json_encode($aReturn_Info);
        $ReceiptLogDAO->status = 2;
        $ReceiptLogDAO->insert();

        //更新本地發票狀態
        if($aReturn_Info['RtnCode']){
            $ReceiptDAO->status = 2;
            $ReceiptDAO->updateStatusById();

            //清除訂單號碼
            $this->/***隱蔽某些code***/->updateReceiptNumber($receiptData->order_id,'');
        }

        return $aReturn_Info;
    }


    /**
     * 發送通知
     */

    public function sentNotification($customer,$receiptSetting,$orderMain, $invoiceNumber,$type='C')
    {

        try
        {
            $sMsg = '' ;

            include_once('Ecpay_Invoice.php');
            $ecpay_invoice = new EcpayInvoice;

            $ecpay_invoice->Invoice_Method 		= 'INVOICE_NOTIFY' ;
            $ecpay_invoice->Invoice_Url 		= 'http://einvoice-stage.ecpay.com.tw/Notify/InvoiceNotify';
            $ecpay_invoice->MerchantID 			= $customer['allpay_storeid'];
            $ecpay_invoice->HashKey 			= $customer['allpay_key'];
            $ecpay_invoice->HashIV 				= $customer['allpay_iv'];

            if(getenv("APP_ENV") == Main::APP_ENV_PRODUCTION  ){
                $ecpay_invoice->Invoice_Url = "http://einvoice.ecpay.com.tw/Notify/InvoiceNotify";
            }

            $ecpay_invoice->Send['InvoiceNo'] 	= $invoiceNumber;

            switch ($receiptSetting->notification_type){
                case 'S':
                    $ecpay_invoice->Send['Phone']       = ($type == 'C')?$orderMain->mobile:$receiptSetting->customer_cell_phone;
                    break;
                case 'E':
                    $ecpay_invoice->Send['NotifyMail']  = ($type == 'C')?$orderMain->email:$receiptSetting->customer_email;
                    break;
                case 'A':
                    $ecpay_invoice->Send['NotifyMail']  = ($type == 'C')?$orderMain->email:$receiptSetting->customer_email;
                    $ecpay_invoice->Send['Phone']       = ($type == 'C')?$orderMain->mobile:$receiptSetting->customer_cell_phone;
                    break;
            }

            $ecpay_invoice->Send['Notify'] 	    = $receiptSetting->notification_type; 			 			// 發送方式
            $ecpay_invoice->Send['InvoiceTag']  = 'I';
            $ecpay_invoice->Send['Notified'] 	= ($type == 'C')?$receiptSetting->notification_to:'C'; 					 	// 發送對象

            //寫入log
            $ReceiptLogDAO = new ReceiptLogDAO();
            $ReceiptLogDAO->customer_id    = $_SESSION['customer_id'];
            $ReceiptLogDAO->receipt_from   = $receiptSetting->receipt_from;
            $ReceiptLogDAO->order_id       = $orderMain->id;
            $ReceiptLogDAO->type           = 'notification';
            $ReceiptLogDAO->log            = json_encode($ecpay_invoice->Send);
            $ReceiptLogDAO->status         = 1;
            $ReceiptLogDAO->insert();

            $aReturn_Info = $ecpay_invoice->Check_Out();

            //紀錄回傳的json
            $ReceiptLogDAO->log      = json_encode($aReturn_Info);
            $ReceiptLogDAO->status   = 2;
            $ReceiptLogDAO->insert();

            return $aReturn_Info;

        }
        catch (Exception $e)
        {
            // 例外錯誤處理。
            $sMsg = $e->getMessage();
        }
        echo $sMsg ;
    }
}