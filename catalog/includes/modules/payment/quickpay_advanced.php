<?php
/*
  version 1.0.0

  osCommerce, Open Source E-Commerce Solutions
  http://www.oscommerce.com

  Copyright (c) 2017 osCommerce

  Released under the GNU General Public License
 */

/** Compatibility fixes */
if (!defined('DIR_WS_CLASSES')) define('DIR_WS_CLASSES','includes/classes/');
if (!defined('DIR_WS_CATALOG_IMAGES')) define('DIR_WS_CATALOG_IMAGES', DIR_WS_CATALOG . 'images/');
if (!defined('DIR_WS_ICONS')) define('DIR_WS_ICONS','images/icons/');
if (!defined('FILENAME_CHECKOUT_CONFIRMATION')) define('FILENAME_CHECKOUT_CONFIRMATION','checkout_confirmation.php');
if (!defined('FILENAME_CHECKOUT_PAYMENT')) define('FILENAME_CHECKOUT_PAYMENT','checkout_payment.php');
if (!defined('FILENAME_CHECKOUT_PROCESS')) define('FILENAME_CHECKOUT_PROCESS','checkout_process.php');
if (!defined('FILENAME_CHECKOUT_SUCCESS')) define('FILENAME_CHECKOUT_SUCCESS','checkout_success.php');

if (!defined('FILENAME_ACCOUNT_HISTORY_INFO')) define('FILENAME_ACCOUNT_HISTORY_INFO','account_history_info.php');
if (!defined('FILENAME_SHIPPING')) define('FILENAME_SHIPPING','shipping.php');

/** You can extend the following cards-array and upload corresponding titled images to images/icons */
if (!defined('MODULE_AVAILABLE_CREDITCARDS'))
define('MODULE_AVAILABLE_CREDITCARDS',array(
    '3d-dankort',
    '3d-jcb',
    '3d-visa',
    '3d-mastercard',
    'mastercard',
    'mastercard-debet',
    'american-express',
    'dankort',
    'diners',
    'jcb',
    'visa',
    'visa-electron',
    'viabill',
    'fbg1886',
    'paypal',
    'sofort',
    'mobilepay',
    'bitcoin',
    'swish',
    'trustly',
    'klarna',
    'maestro',
    'ideal',
    'paysafecard',
    'resurs',
    'vipps',
));

include(DIR_FS_CATALOG.DIR_WS_CLASSES.'QuickpayApi.php');

  class quickpay_advanced extends abstract_payment_module {
    const CONFIG_KEY_BASE = 'MODULE_PAYMENT_QUICKPAY_ADVANCED_';

    public $code = 'quickpay_advanced';

    /** Customize this setting for the number of paymen groups needed */
    public $num_groups = 5;
    public $creditcardgroup;
    public $email_footer;
    public $order_status;

    private $api_version = '1.00';

    public function __construct() {
      parent::__construct();
      global $order,$cardlock;

      if (isset($_POST['cardlock'])) $cardlock = $_POST['cardlock'];

      $this->description = MODULE_PAYMENT_QUICKPAY_ADVANCED_TEXT_DESCRIPTION;
      $this->sort_order = defined('MODULE_PAYMENT_QUICKPAY_ADVANCED_SORT_ORDER') ? MODULE_PAYMENT_QUICKPAY_ADVANCED_SORT_ORDER : 0;
      $this->enabled = (defined('MODULE_PAYMENT_QUICKPAY_ADVANCED_STATUS') && MODULE_PAYMENT_QUICKPAY_ADVANCED_STATUS == 'True') ? (true) : (false);
      $this->creditcardgroup = array();
      $this->email_footer = ($cardlock == "viabill" || $cardlock == "viabill" ? DENUNCIATION : '');
      $this->order_status = (defined('MODULE_PAYMENT_QUICKPAY_ADVANCED_PREPARE_ORDER_STATUS_ID') && ((int)MODULE_PAYMENT_QUICKPAY_ADVANCED_PREPARE_ORDER_STATUS_ID > 0)) ? ((int)MODULE_PAYMENT_QUICKPAY_ADVANCED_PREPARE_ORDER_STATUS_ID) : (0);

      if (is_object($order))
          $this->update_status();

      /** V10 */
      if(isset($_POST['quickpayIT']) && $_POST['quickpayIT'] == "go" && !isset($_SESSION['qlink'])) {
          $this->form_action_url = 'https://payment.quickpay.net/';
      }else{
          $this->form_action_url = tep_href_link(FILENAME_CHECKOUT_CONFIRMATION, '', 'SSL');
      }
    }

    /** Class methods */
    public function update_status() {
        global $order, $quickpay_fee, $HTTP_POST_VARS, $qp_card;

        if (($this->enabled == true) && defined('MODULE_PAYMENT_QUICKPAY_ZONE') && ((int) MODULE_PAYMENT_QUICKPAY_ZONE > 0)) {
            $check_flag = false;
            $check_query = tep_db_query("select zone_id from " . TABLE_ZONES_TO_GEO_ZONES . " where geo_zone_id = '" . MODULE_PAYMENT_QUICKPAY_ZONE . "' and zone_country_id = '" . $order->billing['country']['id'] . "' order by zone_id");
            while ($check = tep_db_fetch_array($check_query)) {
                if ($check['zone_id'] < 1) {
                    $check_flag = true;
                    break;
                } elseif ($check['zone_id'] == $order->billing['zone_id']) {
                    $check_flag = true;
                    break;
                }
            }

            if ($check_flag == false) {
                $this->enabled = false;
            }
        }

        if (!tep_session_is_registered('qp_card'))
            tep_session_register('qp_card');

        if (isset($_POST['qp_card']))
            $qp_card = $_POST['qp_card'];

        if (!tep_session_is_registered('cart_QuickPay_ID'))
            tep_session_register('cart_QuickPay_ID');

        if (isset($_GET['cart_QuickPay_ID']))
            $qp_card = $_GET['cart_QuickPay_ID'];

        if (!tep_session_is_registered('quickpay_fee')) {
            tep_session_register('quickpay_fee');
        }
    }

    public function javascript_validation() {
        $js = '  if (payment_value == "' . $this->code . '") {' . "\n" .
              '      var qp_card_value = null;' . "\n" .
              '      if (document.checkout_payment.qp_card.length) {' . "\n" .
              '          for (var i=0; i<document.checkout_payment.qp_card.length; i++) {' . "\n" .
              '              if (document.checkout_payment.qp_card[i].checked) {' . "\n" .
              '                  qp_card_value = document.checkout_payment.qp_card[i].value;' . "\n" .
              '              }' . "\n" .
              '          }' . "\n" .
              '      } else if (document.checkout_payment.qp_card.checked) {' . "\n" .
              '          qp_card_value = document.checkout_payment.qp_card.value;' . "\n" .
              '      } else if (document.checkout_payment.qp_card.value) {' . "\n" .
              '          qp_card_value = document.checkout_payment.qp_card.value;' . "\n" .
              '          document.checkout_payment.qp_card.checked=true;' . "\n" .
              '      }' . "\n" .
              '      if (qp_card_value == null) {' . "\n" .
              '          error_message = error_message + "' . MODULE_PAYMENT_QUICKPAY_ADVANCED_TEXT_SELECT_CARD . '";' . "\n" .
              '          error = 1;' . "\n" .
              '      }' . "\n" .
              '      if (document.checkout_payment.cardlock.value == null) {' . "\n" .
              '          error_message = error_message + "' . MODULE_PAYMENT_QUICKPAY_ADVANCED_TEXT_SELECT_CARD . '";' . "\n" .
              '          error = 1;' . "\n" .
              '      }' . "\n" .
              '  }' . "\n";
        return $js;
    }

    /* Define payment method selector on checkout page */
    public function selection() {
        global $order, $currencies, $qp_card, $cardlock;
        $qty_groups = 0;

        /** Count how many MODULE_PAYMENT_QUICKPAY_ADVANCED_GROUP are configured. */
        for ($i = 1; $i <= $this->num_groups; $i++) {
            if (constant('MODULE_PAYMENT_QUICKPAY_ADVANCED_GROUP' . $i) == '') {
                continue;
            }
            $qty_groups++;
        }

        if($qty_groups > 1) {
            $selection = array('id' => $this->code, 'module' => $this->title. tep_draw_hidden_field('cardlock', $cardlock ));
        }

        /** Parse all the configured MODULE_PAYMENT_QUICKPAY_ADVANCED_GROUP */
        $selection['fields'] = array();
        $msg = '<table width="100%"><tr style="background-color: transparent !important;border-top: 0 !important;"><td style="background-color: transparent !important;border-top: 0 !important;">';
        $optscount=0;
        for ($i = 1; $i <= $this->num_groups; $i++) {
            $options_text = '';
            if (defined('MODULE_PAYMENT_QUICKPAY_ADVANCED_GROUP' . $i) && constant('MODULE_PAYMENT_QUICKPAY_ADVANCED_GROUP' . $i) != '') {
                $payment_options = preg_split('[\,\;]', constant('MODULE_PAYMENT_QUICKPAY_ADVANCED_GROUP' . $i));
                foreach ($payment_options as $option) {

                    $cost = (MODULE_PAYMENT_QUICKPAY_ADVANCED_AUTOFEE == "No" || $option == 'viabill' ? "0" : "1");
                    if($option=="creditcard"){

						$msg .= "<div class='creditcard_pm_title'>";

                        /** Configuring the text to be shown for the payment group. If there is an input in the text field for that payment option, that value will be shown to the user, otherwise, the default value will be used.*/
                        if(defined('MODULE_PAYMENT_QUICKPAY_ADVANCED_GROUP'.$i.'_TEXT') && constant('MODULE_PAYMENT_QUICKPAY_ADVANCED_GROUP' . $i . '_TEXT') != ''){
                            $msg .= constant('MODULE_PAYMENT_QUICKPAY_ADVANCED_GROUP' . $i . '_TEXT')."</div>";
                        }else {
                            $msg .= $this->get_payment_options_name($option)."</div>";
                        }

                        $msg .= "<br>";

						$optscount++;
                        /** Read the logos defined on admin panel **/
                        $cards = explode(";",MODULE_PAYMENT_QUICKPAY_CARD_LOGOS);
                        foreach ($cards as $optionc) {
                            $iconc = "";
                            if(file_exists(DIR_WS_ICONS.$optionc.".png")){
                              $iconc = DIR_WS_ICONS.$optionc.".png";
                            }elseif(file_exists(DIR_WS_ICONS.$optionc.".jpg")){
                              $iconc = DIR_WS_ICONS.$optionc.".jpg";
                            }elseif(file_exists(DIR_WS_ICONS.$optionc.".gif")){
                              $iconc = DIR_WS_ICONS.$optionc.".gif";
                            }

                            /** Define payment icons width */
                            $w = 35;
                            $h = 22;
                            $space = 5;

                            $msg .= tep_image($iconc,$optionc,$w,$h,'style="position:relative;border:0px;float:left;margin:'.$space.'px;" ');
                        }




                        $msg .= '</td></tr></table>';
              			$options_text=$msg;

                        if($qty_groups==1){
                            $selection = array(
                                'id' => $this->code,
                                'module' => '<table width="100%" border="0">
                                                <tr class="moduleRow table-selection" onclick="selectQuickPayRowEffect(this, ' . ($optscount-1) . ',\''.$option.'\');event.stopImmediatePropagation();">
                                                    <td class="main" style="height:22px;vertical-align:middle;background-color: transparent !important;border-top: 0 !important;">' .$options_text.($cost !=0 ? '</td><td class="main" style="height:22px;vertical-align:middle;background-color: transparent !important;border-top: 0 !important;"> (+ '.MODULE_PAYMENT_QUICKPAY_ADVANCED_FEELOCKINFO.')' :'').'
                                                        </td>
                                                </tr>'.'
                                            </table>'.tep_draw_hidden_field('cardlock', $option));


                        }else{
                            $selection['fields'][] = array(
                                'title' => '<table width="100%" border="0">
                                                <tr class="moduleRow table-selection" style="background-color: transparent !important;border-top: 0 !important;" onclick="selectQuickPayRowEffect(this, ' . ($optscount-1) . ',\''.$option.'\');event.stopImmediatePropagation();">
                                                    <td class="main" style="height:22px;vertical-align:middle;background-color: transparent !important;border-top: 0 !important;">' . $options_text.($cost !=0 ? '</td><td style="height:22px;vertical-align:middle;background-color: transparent !important;border-top: 0 !important;">(+ '.MODULE_PAYMENT_QUICKPAY_ADVANCED_FEELOCKINFO.')' :'').'
                                                        </td>
                                                </tr>'.'
                                            </table>',
                                'field' => tep_draw_radio_field(
                                    'qp_card',
                                    '',
                                    ($option==$cardlock ? true : false),
                                    ' onClick="setQuickPay(); document.checkout_payment.cardlock.value = \''.$option.'\';event.stopImmediatePropagation();" '
                                )
                            );
                        }/** end qty=1 */
                    }

                    if($option != "creditcard"){
                        /** upload images to images/icons corresponding to your chosen cardlock groups in your payment module settings */
                        $selectedopts = explode(",", $option);
                        $icon = "";
                        foreach($selectedopts as $option){
                            $optscount++;

                            $icon = "";
                            if(file_exists(DIR_WS_ICONS.$option.".png")){
                              $icon = DIR_WS_ICONS.$option.".png";
                            }elseif(file_exists(DIR_WS_ICONS.$option.".jpg")){
                              $icon = DIR_WS_ICONS.$option.".jpg";
                            }elseif(file_exists(DIR_WS_ICONS.$option.".gif")){
                              $icon = DIR_WS_ICONS.$option.".gif";
                            }elseif(file_exists(DIR_WS_ICONS . $option . "_payment.png")){
                              $icon = DIR_WS_ICONS . $option . "_payment.png";
                            }elseif(file_exists(DIR_WS_ICONS . $option . "_payment.jpg")){
                              $icon = DIR_WS_ICONS . $option . "_payment.jpg";
                            }elseif(file_exists(DIR_WS_ICONS . $option . "_payment.gif")){
                              $icon = DIR_WS_ICONS . $option . "_payment.gif";
                            }
                            $space = 5;

                            //define payment icon width
                            if(strstr($icon, "_payment")){
                                $w = 120;
                                $h = 27;
                                if(strstr($icon, "3d")){
                                    $w = 60;
                                }
                            }else{
                                $w = 35;
                                $h = 22;
                            }

                            /** Configuring the text to be shown for the payment option. */
                            $options_text = '<table width="100%">
                                                <tr style="background-color: transparent !important;border-top: 0 !important;">
                                                    <td style="background-color: transparent !important;border-top: 0 !important;">'.tep_image($icon,$this->get_payment_options_name($option),$w,$h,' style="position:relative;border:0px;float:left;margin:'.$space.'px;" ').'</td>
                                                    <td style="height: 27px;white-space:nowrap;vertical-align:middle;background-color: transparent !important;border-top: 0 !important;" >';

                            /** If there is an input in the text field for that payment option, that value will be shown to the user, otherwise, the default value will be used. */
                            if(defined('MODULE_PAYMENT_QUICKPAY_ADVANCED_GROUP'.$i.'_TEXT') && constant('MODULE_PAYMENT_QUICKPAY_ADVANCED_GROUP' . $i . '_TEXT') != ''){
                                $options_text .= constant('MODULE_PAYMENT_QUICKPAY_ADVANCED_GROUP' . $i . '_TEXT').'</td></tr></table>';
                            }else {
                                $options_text .= $this->get_payment_options_name($option).'</td></tr></table>';
                            }


                            if($qty_groups==1){
                                $selection = array(
                                    'id' => $this->code,
                                    'module' => '<table width="100%" border="0">
                                                    <tr class="moduleRow table-selection" onclick="selectQuickPayRowEffect(this, ' . ($optscount-1) . ',\''.$option.'\');event.stopImmediatePropagation();">
                                                        <td class="main" style="height: 27px;white-space:nowrap;vertical-align:middle;background-color: transparent !important;border-top: 0 !important;">' .$options_text.($cost !=0 ? '</td><td style="height:22px;vertical-align:middle;background-color: transparent !important;border-top: 0 !important;"> (+ '.MODULE_PAYMENT_QUICKPAY_ADVANCED_FEELOCKINFO.')' :'').'
                                                            </td>
                                                    </tr>'.'
                                                </table>'.tep_draw_hidden_field('cardlock', $option).tep_draw_hidden_field('qp_card', (isset($fees[1])) ? $fees[1] : '0'));
                            }else{
                                $selection['fields'][] = array(
                                    'title' => '<table width="100%" border="0">
                                                    <tr class="moduleRow table-selection" style="background-color: transparent !important;border-top: 0 !important;" onclick="selectQuickPayRowEffect(this, ' . ($optscount-1) . ',\''.$option.'\');event.stopImmediatePropagation();">
                                                        <td class="main" style="height: 27px;white-space:nowrap;vertical-align:middle;background-color: transparent !important;border-top: 0 !important;">' . $options_text.($cost !=0 ? '</td><td style="height:22px;vertical-align:middle;background-color: transparent !important;border-top: 0 !important;"> (+ '.MODULE_PAYMENT_QUICKPAY_ADVANCED_FEELOCKINFO.')' :'').'
                                                            </td>
                                                    </tr>'.'
                                                </table>',
                                    'field' => tep_draw_radio_field(
                                        'qp_card',
                                        '',
                                        ($option==$cardlock ? true : false),
                                        ' onClick="setQuickPay();document.checkout_payment.cardlock.value = \''.$option.'\';event.stopImmediatePropagation()" '
                                    )
                                );
                            }
                        }
                    }
                }
            }
        }

        $js_function = '<script language="javascript"><!--
                            function setQuickPay() {
                                var radioLength = document.checkout_payment.payment.length;
                                for(var i = 0; i < radioLength; i++) {
                                    document.checkout_payment.payment[i].checked = false;
                                    if(document.checkout_payment.payment[i].value == "quickpay_advanced") {
                                        document.checkout_payment.payment[i].checked = true;
                                    }
                                }
                            }

                            function selectQuickPayRowEffect(object, buttonSelect, option) {
                                if (typeof selected !== "undefined" && selected !== null) {
                                  if (!selected) {
                                      if (document.getElementById) {
                                          selected = document.getElementById("defaultSelected");
                                      } else {
                                          selected = document.all["defaultSelected"];
                                      }
                                  }
                                  if (selected) selected.className = "moduleRow";
                                }

                                object.className = "moduleRowSelected";
                                selected = object;
                                document.checkout_payment.cardlock.value = option;
                                document.checkout_payment.qp_card.checked = false;

                                if (document.checkout_payment.qp_card[0]) {
                                    document.checkout_payment.qp_card[buttonSelect].checked=true;
                                } else {
                                    document.checkout_payment.qp_card.checked=true;
                                }
                                setQuickPay();
                            }

                        //--></script>';

        $selection['module'] .= $js_function;
        return $selection;
    }

    /* Before order is confirmed hook*/
    public function pre_confirmation_check() {
        global $cartID, $cart, $cardlock;

        if (!tep_session_is_registered('cardlock')) {
            tep_session_register('cardlock');
        }

        if (empty($cart->cartID)) {
            $cartID = $cart->cartID = $cart->generate_cart_id();
        }

        if (!tep_session_is_registered('cartID')) {
            tep_session_register('cartID');
        }

        $this->get_order_fee();
    }

    /* Order confirmation page hook*/
    public function confirmation($addorder=false) {
        global $order, $cart_QuickPay_ID;
        $order_id = substr($cart_QuickPay_ID, strpos($cart_QuickPay_ID, '-') + 1);

        /** Do not create preparing order id before payment confirmation is chosen by customer */
        $mode = false;
        if(MODULE_PAYMENT_QUICKPAY_ADVANCED_MODE == "Before" || (isset($_POST['callquickpay']) && $_POST['callquickpay'] == "go")){
            $mode = true;
        }

        if($mode && !$order_id) {
            if(!isset($order->customer['company']))$order->customer['company']='';
            if(!isset($order->billing['company']))$order->billing['company']='';
            if(!isset($order->delivery['company']))$order->delivery['company']='';
            if(!isset($order->customer['suburb']))$order->customer['suburb']='';
            if(!isset($order->billing['suburb']))$order->billing['suburb']='';
            if(!isset($order->delivery['suburb']))$order->delivery['suburb']='';
            require 'includes/modules/checkout/build_order_totals.php';
            require 'includes/modules/checkout/insert_order.php';

            $_SESSION['cart_QuickPay_ID'] = $_SESSION['cartID'] . '-' . $order->get_id();
        }

        $fee_info = (MODULE_PAYMENT_QUICKPAY_ADVANCED_AUTOFEE =="Yes" && $_POST["cardlock"] !="viabill" ? MODULE_PAYMENT_QUICKPAY_ADVANCED_FEEINFO . '<br />' : '');

        return array('title' => $fee_info . $this->email_footer);
    }

    /* Define payment button and data array to be sent */
    public function process_button() {
        global $_POST, $customer_id, $order, $currencies, $languages_id, $language, $cart_QuickPay_ID, $messageStack;

        /** Collect all post fields and attach as hiddenfieds to button */
        if ( !class_exists('quickpay_currencies') ) {
            include(DIR_FS_CATALOG . DIR_WS_CLASSES . 'quickpay_currencies.php');
        }
        if (!($currencies instanceof quickpay_currencies)) {
            $currencies = new quickpay_currencies($currencies);
        }

        $process_button_string = '';
        $process_fields ='';
        $process_parameters = array();

        $qp_merchant_id = MODULE_PAYMENT_QUICKPAY_ADVANCED_MERCHANTID;
        $qp_agreement_id = MODULE_PAYMENT_QUICKPAY_ADVANCED_AGGREEMENTID;

        /** TODO: dynamic language switching instead of hardcoded mapping */
        $qp_language = "da";
        switch ($language) {
            case "english": $qp_language = "en";
                break;
            case "swedish": $qp_language = "se";
                break;
            case "norwegian": $qp_language = "no";
                break;
            case "german": $qp_language = "de";
                break;
            case "french": $qp_language = "fr";
                break;
        }
        $qp_branding_id = "";

        $qp_subscription = (MODULE_PAYMENT_QUICKPAY_ADVANCED_SUBSCRIPTION == "Normal" ? "" : "1");
        $qp_cardtypelock = $_POST['cardlock'];
        $qp_autofee = (MODULE_PAYMENT_QUICKPAY_ADVANCED_AUTOFEE == "No" || $qp_cardtypelock == 'viabill' ? "0" : "1");
        $qp_description = "Merchant ".$qp_merchant_id." ".(MODULE_PAYMENT_QUICKPAY_ADVANCED_SUBSCRIPTION == "Normal" ? "Authorize" : "Subscription");
        $order_id = substr($cart_QuickPay_ID, strpos($cart_QuickPay_ID, '-') + 1);
        $qp_order_id = MODULE_PAYMENT_QUICKPAY_ADVANCED_ORDERPREFIX.sprintf('%04d', $order_id);
        /** Calculate the total order amount for the order (the same way as in checkout_process.php) */
        $qp_order_amount = 100 * $currencies->calculate($order->info['total'], true, $order->info['currency'], $order->info['currency_value'], '.', '');
        $qp_currency_code = $order->info['currency'];
        $qp_continueurl = tep_href_link(FILENAME_CHECKOUT_PROCESS, 'cart_QuickPay_ID='.$cart_QuickPay_ID, 'SSL');
        $qp_cancelurl = tep_href_link(FILENAME_CHECKOUT_PAYMENT, 'payment_error=' . $this->code, 'SSL');
        $qp_callbackurl = tep_href_link('callback10.php','oid='.$order_id,'SSL');
        $qp_autocapture = (MODULE_PAYMENT_QUICKPAY_ADVANCED_AUTOCAPTURE == "No" ? "0" : "1");
        $qp_version ="v10";

        /** Custom vars */
        $varsvalues = array(
            'variables[customers_id]' => $customer_id,
            'variables[customers_name]' =>  (isset($order->customer['firstname'])?$order->customer['firstname']:'') . ' ' . (isset($order->customer['lastname'])?$order->customer['lastname']:''),
            'variables[customers_company]' => isset($order->customer['company'])?$order->customer['company']:'',
            'variables[customers_street_address]' => isset($order->customer['street_address'])?$order->customer['street_address']:'',
            'variables[customers_suburb]' => isset($order->customer['suburb'])?$order->customer['suburb']:'',
            'variables[customers_city]' => isset($order->customer['city'])?$order->customer['city']:'',
            'variables[customers_postcode]' => isset($order->customer['postcode'])?$order->customer['postcode']:'',
            'variables[customers_state]' => isset($order->customer['state'])?$order->customer['state']:'',
            'variables[customers_country]' => isset($order->customer['country']['title'])?$order->customer['country']['title']:'',
            'variables[customers_telephone]' => isset($order->customer['telephone'])?$order->customer['telephone']:'',
            'variables[customers_email_address]' => isset($order->customer['email_address'])?$order->customer['email_address']:'',
            'variables[delivery_name]' => (isset($order->delivery['firstname'])?$order->delivery['firstname']:'') . ' ' . (isset($order->delivery['lastname'])?$order->delivery['lastname']:''),
            'variables[delivery_company]' => isset($order->delivery['company'])?$order->delivery['company']:'',
            'variables[delivery_street_address]' => isset($order->delivery['street_address'])?$order->delivery['street_address']:'',
            'variables[delivery_suburb]' => isset($order->delivery['suburb'])?$order->delivery['suburb']:'',
            'variables[delivery_city]' => isset($order->delivery['city'])?$order->delivery['city']:'',
            'variables[delivery_postcode]' => isset($order->delivery['postcode'])?$order->delivery['postcode']:'',
            'variables[delivery_state]' => isset($order->delivery['state'])?$order->delivery['state']:'',
            'variables[delivery_country]' => isset($order->delivery['country']['title'])?$order->delivery['country']['title']:'',
            'variables[delivery_address_format_id]' => isset($order->delivery['format_id'])?$order->delivery['format_id']:'',
            'variables[billing_name]' => (isset($order->billing['firstname'])?$order->billing['firstname']:'') . ' ' . (isset($order->billing['lastname'])?$order->billing['lastname']:''),
            'variables[billing_company]' => isset($order->billing['company'])?$order->billing['company']:'',
            'variables[billing_street_address]' => isset($order->billing['street_address'])?$order->billing['street_address']:'',
            'variables[billing_suburb]' => isset($order->billing['suburb'])?$order->billing['suburb']:'',
            'variables[billing_city]' => isset($order->billing['city'])?$order->billing['city']:'',
            'variables[billing_postcode]' => isset($order->billing['postcode'])?$order->billing['postcode']:'',
            'variables[billing_state]' => isset($order->billing['state'])?$order->billing['state']:'',
            'variables[billing_country]' => isset($order->billing['country']['title'])?$order->billing['country']['title']:''
        );


        for ($i = 0, $n = sizeof($order->products); $i < $n; $i++) {
            $order_products_id = tep_get_prid($order->products[$i]['id']);

            /** Insert customer choosen option to order */
            $attributes_exist = '0';
            $products_ordered_attributes = '';
            if (isset($order->products[$i]['attributes'])) {
                $attributes_exist = '1';
                for ($j = 0, $n2 = sizeof($order->products[$i]['attributes']); $j < $n2; $j++) {
                    if (DOWNLOAD_ENABLED == 'true') {
                        $attributes_query = "select popt.products_options_name, poval.products_options_values_name, pa.options_values_price, pa.price_prefix, pad.products_attributes_maxdays, pad.products_attributes_maxcount , pad.products_attributes_filename
                        from products_options popt, products_options_values poval, products_attributes pa
                        left join products_attributes_download pad
                        on pa.products_attributes_id=pad.products_attributes_id
                        where pa.products_id = '" . $order->products[$i]['id'] . "'
                        and pa.options_id = '" . $order->products[$i]['attributes'][$j]['option_id'] . "'
                        and pa.options_id = popt.products_options_id
                        and pa.options_values_id = '" . $order->products[$i]['attributes'][$j]['value_id'] . "'
                        and pa.options_values_id = poval.products_options_values_id
                        and popt.language_id = '" . $languages_id . "'
                        and poval.language_id = '" . $languages_id . "'";
                        $attributes = tep_db_query($attributes_query);
                    } else {
                        $attributes = tep_db_query("select popt.products_options_name, poval.products_options_values_name, pa.options_values_price, pa.price_prefix from products_options popt, products_options_values poval, products_attributes pa where pa.products_id = '" . $order->products[$i]['id'] . "' and pa.options_id = '" . $order->products[$i]['attributes'][$j]['option_id'] . "' and pa.options_id = popt.products_options_id and pa.options_values_id = '" . $order->products[$i]['attributes'][$j]['value_id'] . "' and pa.options_values_id = poval.products_options_values_id and popt.language_id = '" . $languages_id . "' and poval.language_id = '" . $languages_id . "'");
                    }
                    $attributes_values = tep_db_fetch_array($attributes);

                    if ((DOWNLOAD_ENABLED == 'true') && isset($attributes_values['products_attributes_filename']) && tep_not_null($attributes_values['products_attributes_filename'])) {

                    }
                    $products_ordered_attributes .= "(" . $attributes_values['products_options_name'] . ' ' . $attributes_values['products_options_values_name'].") ";
                }
            }

            $products_ordered[] = $order->products[$i]['qty'] . ' x ' . $order->products[$i]['name'] . ' (' . $order->products[$i]['model'] . ') = ' . $currencies->display_price($order->products[$i]['final_price'], $order->products[$i]['tax'], $order->products[$i]['qty']) . $products_ordered_attributes . "-";

        }

        $ps="";
        while (list ($key, $value) = myEach($products_ordered)) {
            $ps .= $value;
        }

        $varsvalues["variables[products]"] = html_entity_decode($ps);
        $varsvalues["variables[shopsystem]"] = "OsCommerce";
        /** end custom vars */

        /** Register fields to hand over */
        $process_parameters = array(
            'agreement_id'                 => $qp_agreement_id,
            'amount'                       => $qp_order_amount,
            'autocapture'                  => $qp_autocapture,
            'autofee'                      => $qp_autofee,
            // 'branding_id'                  => $qp_branding_id,
            'callbackurl'                  => $qp_callbackurl,
            'cancelurl'                    => $qp_cancelurl,
            'continueurl'                  => $qp_continueurl,
            'currency'                     => $qp_currency_code,
            'description'                  => $qp_description,
            // 'google_analytics_client_id'   => $qp_google_analytics_client_id,
            // 'google_analytics_tracking_id' => $analytics_tracking_id,
            'language'                     => $qp_language,
            'merchant_id'                  => $qp_merchant_id,
            'order_id'                     => $qp_order_id,
            'payment_methods'              => $qp_cardtypelock,
            // 'product_id'                   => $qp_product_id,
            // 'category'                     => $qp_category,
            // 'reference_title'              => $qp_reference_title,
            // 'vat_amount'                   => $qp_vat_amount,
            'subscription'                 => $qp_subscription,
            'version'                      => 'v10'
        );


        $process_parameters = array_merge($process_parameters,$varsvalues);

        if(isset($_POST['callquickpay']) && $_POST['callquickpay'] == "go") {
            $apiorder= new QuickpayApi();
            $apiorder->setOptions(MODULE_PAYMENT_QUICKPAY_ADVANCED_USERAPIKEY);
            /** Set status request mode */
            $mode = (MODULE_PAYMENT_QUICKPAY_ADVANCED_SUBSCRIPTION == "Normal" ? "" : "1");
            $exists = $this->get_quickpay_order_status($order_id, $mode);

            $qid = $exists["qid"];
            /** Set to create/update mode */
            $apiorder->mode = (MODULE_PAYMENT_QUICKPAY_ADVANCED_SUBSCRIPTION == "Normal" ? "payments/" : "subscriptions/");

            if($exists["qid"] == null){
                /** Create new quickpay order */
                $storder = $apiorder->createorder($qp_order_id, $qp_currency_code, $process_parameters);
                $qid = $storder["id"];

            }else{
                $qid = $exists["qid"];
            }

            $storder = $apiorder->link($qid, $process_parameters);

            if (substr($storder['url'],0,5) <> 'https') {
                $messageStack->add_session(MODULE_PAYMENT_QUICKPAY_ADVANCED_ERROR_COMMUNICATION_FAILURE, 'error');
                tep_redirect(tep_href_link(FILENAME_CHECKOUT_PAYMENT, 'payment_error=' . $this->code, 'SSL'));
            }


            $process_button_string .= "
                <script>
                    // alert('qp ".$qp_order_id."-".$order_id."');
                    window.location.replace('".$storder['url']."');
                </script>";
        }
        print_r("<pre>");

        $process_button_string .=  "<input type='hidden' value='go' name='callquickpay' />". "\n".
                                   "<input type='hidden' value='" . $_POST['cardlock'] . "' name='cardlock' />";

        return $process_button_string;
    }

    /* Before order is processed */
    public function before_process() {
        /** Called in FILENAME_CHECKOUT_PROCESS */
        /** check if order is approved by callback */
        global $order, $cart_QuickPay_ID;

        $order_id = substr($cart_QuickPay_ID, strpos($cart_QuickPay_ID, '-') + 1);
        $order_status_approved_id = (MODULE_PAYMENT_QUICKPAY_ADVANCED_ORDER_STATUS_ID > 0 ? (int) MODULE_PAYMENT_QUICKPAY_ADVANCED_ORDER_STATUS_ID : (int) DEFAULT_ORDERS_STATUS_ID);

        $mode = (MODULE_PAYMENT_QUICKPAY_ADVANCED_SUBSCRIPTION == "Normal" ? "" : "1");
        $checkorderid = $this->get_quickpay_order_status($order_id, $mode);
        if($checkorderid["oid"] != $order_id){
            tep_redirect(tep_href_link(FILENAME_CHECKOUT_PAYMENT, 'payment_error=' . $this->code, 'SSL'));
        }

        if ( !class_exists('quickpay_order') ) {
            include(DIR_FS_CATALOG . DIR_WS_CLASSES . 'quickpay_order.php');
        }

        if (!($order instanceof quickpay_order)) {
            $order = new quickpay_order($order);
        }

        /** For debugging with FireBug / FirePHP */
        global $firephp;
        if (isset($firephp)) {
            $firephp->log($order_id, 'order_id');
        }

        /** Update order status */
        tep_db_query("update orders set orders_status = '" . (int)$order_status_approved_id . "', last_modified = now() where orders_id = '" . (int)$order_id . "'");
        $customer_notification = (SEND_EMAILS == 'true') ? '1' : '0';
        $sql_data = [
          'orders_id' => $order_id,
          'orders_status_id' => (int)$order_status_approved_id,
          'date_added' => 'now()',
          'customer_notified' => $customer_notification,
          'comments' => $order->info['comments'],
        ];
        tep_db_perform('orders_status_history', $sql_data);

        include 'includes/modules/checkout/after.php';

        /** Load the after_process function from the payment modules */
        $this->after_process();
    }

    /* After order is processed */
    public function after_process() {
        tep_session_unregister('cardlock');
        tep_session_unregister('order_id');
        tep_session_unregister('quickpay_fee');
        tep_session_unregister('qp_card');
        tep_session_unregister('cart_QuickPay_ID');
        tep_session_unregister('qlink');

        include 'includes/modules/checkout/reset.php';
        tep_redirect(tep_href_link(FILENAME_CHECKOUT_SUCCESS, '', 'SSL'));
        require 'includes/application_bottom.php';
    }

    public function get_error() {
        global $cart_QuickPay_ID, $order, $currencies;
        $order_id = substr($cart_QuickPay_ID, strpos($cart_QuickPay_ID, '-') + 1);;

        if ( !class_exists('quickpay_currencies') ) {
            include(DIR_FS_CATALOG . DIR_WS_CLASSES . 'quickpay_currencies.php');
        }
        if (!($currencies instanceof quickpay_currencies)) {
            $currencies = new quickpay_currencies($currencies);
        }

        $error_desc = MODULE_PAYMENT_QUICKPAY_ADVANCED_ERROR_CANCELLED;
        $error = array('title' => MODULE_PAYMENT_QUICKPAY_ADVANCED_TEXT_ERROR, 'error' => $error_desc);

        return $error;
    }

    public function output_error() {
        return false;
    }

    /* Define module admin fields and statuses */
    protected function get_parameters() {
      $cc_query = tep_db_query("describe orders cc_transactionid");
      if (tep_db_num_rows($cc_query) == 0) {
          tep_db_query("ALTER TABLE orders ADD cc_transactionid VARCHAR( 64 ) NULL default NULL");
      }
      $cc_query = tep_db_query("describe orders cc_cardhash");
      if (tep_db_num_rows($cc_query) == 0) {
          tep_db_query("ALTER TABLE orders ADD cc_cardhash VARCHAR( 64 ) NULL default NULL");
      }
      $cc_query = tep_db_query("describe orders cc_cardtype");
      if (tep_db_num_rows($cc_query) == 0) {
          tep_db_query("ALTER TABLE orders ADD cc_cardtype VARCHAR( 64 ) NULL default NULL");
      }
      tep_db_query("ALTER TABLE orders CHANGE cc_expires  cc_expires VARCHAR( 8 )  NULL DEFAULT NULL");

      $fields = array(
        'MODULE_PAYMENT_QUICKPAY_ADVANCED_STATUS' => [
          'title' => 'Enable quickpay_advanced',
          'desc' => 'Do you want to accept quickpay payments?',
          'value' => 'False',
          'set_func' => "tep_cfg_select_option(['True', 'False'], ",
        ],

        'MODULE_PAYMENT_QUICKPAY_ADVANCED_ZONE' => [
          'title' => 'Payment Zone',
          'value' => '0',
          'desc' => 'If a zone is selected, only enable this payment method for that zone.',
          'use_func' => 'tep_get_zone_class_title',
          'set_func' => 'tep_cfg_pull_down_zone_classes(',
        ],

        'MODULE_PAYMENT_MONEYORDER_SORT_ORDER' => [
          'title' => 'Sort order of display.',
          'value' => '0',
          'desc' => 'Sort order of display. Lowest is displayed first.',
        ],

        'MODULE_PAYMENT_QUICKPAY_ADVANCED_MERCHANTID' => [
          'title' => 'Quickpay Merchant Id',
          'desc' => 'Enter Merchant id',
        ],

        'MODULE_PAYMENT_QUICKPAY_ADVANCED_AGGREEMENTID' => [
          'title' => 'Quickpay Window user Agreement Id',
          'desc' => 'Enter Window user Agreement id',
        ],

        'MODULE_PAYMENT_QUICKPAY_ADVANCED_USERAPIKEY' => [
          'title' => 'API USER KEY',
          'desc' => 'Used for payments, and for handling transactions from your backend order page.',
        ],

        'MODULE_PAYMENT_QUICKPAY_ADVANCED_ORDERPREFIX' => [
          'title' => 'Order number prefix',
          'value' => '000',
          'desc' => 'Enter prefix (Ordernumbers Must contain at least 3 characters)<br>Please Note: if upgrading from previous versions of Quickpay 10, use format \"Window Agreement ID_\" ex. 1234_ if \"old\" orders statuses  are to be displayed in your order admin.<br>',
        ],

        'MODULE_PAYMENT_QUICKPAY_ADVANCED_PREPARE_ORDER_STATUS_ID' => [
          'title' => 'Set Preparing Order Status',
          'value' => self::ensure_order_status('MODULE_PAYMENT_QUICKPAY_ADVANCED_ORDER_PREPARE_STATUS_ID', 'Quickpay [preparing]'),
          'desc' => 'Set the status of prepared orders made with this payment module to this value',
          'set_func' => 'tep_cfg_pull_down_order_statuses(',
          'use_func' => 'tep_get_order_status_name',
        ],

        'MODULE_PAYMENT_QUICKPAY_ADVANCED_ORDER_STATUS_ID' => [
          'title' => 'Set Quickpay Acknowledged Order Status',
          'value' => self::ensure_order_status('MODULE_PAYMENT_QUICKPAY_ADVANCED_ORDER_APPROVED_STATUS_ID', 'Quickpay [approved]'),
          'desc' => 'Set the status of orders made with this payment module to this value',
          'set_func' => 'tep_cfg_pull_down_order_statuses(',
          'use_func' => 'tep_get_order_status_name',
        ],

        'MODULE_PAYMENT_QUICKPAY_ADVANCED_REJECTED_ORDER_STATUS_ID' => [
          'title' => 'Set Quickpay Rejected Order Status',
          'value' => self::ensure_order_status('MODULE_PAYMENT_QUICKPAY_ADVANCED_ORDER_REJECTED_STATUS_ID', 'Quickpay [rejected]'),
          'desc' => 'Set the status of rejected orders made with this payment module to this value',
          'set_func' => 'tep_cfg_pull_down_order_statuses(',
          'use_func' => 'tep_get_order_status_name',
        ],

        'MODULE_PAYMENT_QUICKPAY_ADVANCED_SUBSCRIPTION' => [
          'title' => 'Subscription payment',
          'desc' => 'Set Subscription payment as default (normal is single payment).',
          'value' => 'Normal',
          'set_func' => "tep_cfg_select_option(['Normal', 'Subscription'], ",
        ],

        'MODULE_PAYMENT_QUICKPAY_ADVANCED_AUTOFEE' => [
          'title' => 'Autofee',
          'desc' => 'Does customer pay the cardfee?<br>Set fees in <a href=\"https://manage.quickpay.net/\" target=\"_blank\"><u>Quickpay manager</u></a>',
          'value' => 'No',
          'set_func' => "tep_cfg_select_option(['Yes', 'No'], ",
        ],

        'MODULE_PAYMENT_QUICKPAY_ADVANCED_AUTOCAPTURE' => [
          'title' => 'Autocapture',
          'desc' => 'Use autocapture?',
          'value' => 'No',
          'set_func' => "tep_cfg_select_option(['Yes', 'No'], ",
        ],

        'MODULE_PAYMENT_QUICKPAY_ADVANCED_MODE' => [
          'title' => 'Preparing orders mode',
          'desc' => 'Choose mode:<br><b>Normal:</b> Create when payment window is opened.<br><b>Before:</b> Create when confirmation page is opened',
          'value' => 'Normal',
          'set_func' => "tep_cfg_select_option(['Normal', 'Before'], ",
        ],

        'MODULE_PAYMENT_QUICKPAY_CARD_LOGOS' => [
          'title' => 'Credit Card Logos',
          'value' => implode(";",MODULE_AVAILABLE_CREDITCARDS),
          'desc' => 'Images related to Credit Card Payment Method. Drag & Drop to change the visibility/order',
          'set_func' => 'edit_logos(',
          'use_func' => 'show_logos',
        ]
      );

      /* Set statuses public_flag to 1 in order to be shown on front store */
	    tep_db_query("update orders_status set public_flag = 1 and downloads_flag = 0 where orders_status_id = '" . (int) MODULE_PAYMENT_QUICKPAY_ADVANCED_ORDER_STATUS_ID . "'");
	    tep_db_query("update orders_status set public_flag = 1 and downloads_flag = 0 where orders_status_id = '" . (int) MODULE_PAYMENT_QUICKPAY_ADVANCED_REJECTED_ORDER_STATUS_ID . "'");
	    tep_db_query("update orders_status set public_flag = 1 and downloads_flag = 0 where orders_status_id = '" . (int) MODULE_PAYMENT_QUICKPAY_ADVANCED_PREPARE_ORDER_STATUS_ID . "'");

      for ($i = 1; $i <= $this->num_groups; $i++) {

          if($i==1){
              $defaultlock='viabill';
          }else if($i==2){
              $defaultlock='creditcard';
          }else{
              $defaultlock='';
          }

          $qp_group = (defined('MODULE_PAYMENT_QUICKPAY_GROUP' . $i)) ? constant('MODULE_PAYMENT_QUICKPAY_GROUP' . $i) : $defaultlock;

          $group_field = array('MODULE_PAYMENT_QUICKPAY_ADVANCED_GROUP'.$i => [
            'title' => 'Group '.$i.' Payment Options',
            'value' => $qp_group,
            'desc' => 'Comma seperated Quickpay payment options that are included in Group '.$i.', maximum 255 chars (<a href=\'http://tech.quickpay.net/appendixes/payment-methods\' target=\'_blank\'><u>available options</u></a>)<br>Example: creditcard OR viabill OR dankort<br>',
          ]);

          //Added a text field key for each payment group
          $text_field = array('MODULE_PAYMENT_QUICKPAY_ADVANCED_GROUP'.$i.'_TEXT' => [
            'title' => 'Group '.$i.' Payment Text',
            'value' => '',
            'desc' => 'Define text to be displayed for Group ' . $i . ' Payment Option. If this is not defined, the default text will be shown.<br>',
          ]);

          $fields = array_merge($fields, $group_field);

          $fields = array_merge($fields, $text_field);
      }

      return $fields;
    }

    /** Internal help functions */
    /** $order_total parameter must be total amount for current order including tax */
    /** Format of $fee parameter: "[fixed fee]:[percentage fee]" */
    protected function calculate_order_fee($order_total, $fee) {
        list($fixed_fee, $percent_fee) = explode(':', $fee);

        return ((float) $fixed_fee + (float) $order_total * ($percent_fee / 100));
    }

    protected function get_order_fee() {
        global $_POST, $order, $currencies, $quickpay_fee;
        $quickpay_fee = 0.0;
        if (isset($_POST['qp_card']) && strpos($_POST['qp_card'], ":")) {
            $quickpay_fee = $this->calculate_order_fee($order->info['total'], $_POST['qp_card']);
        }
    }

    protected function get_payment_options_name($payment_option) {
        switch ($payment_option) {
            case 'creditcard': return MODULE_PAYMENT_QUICKPAY_ADVANCED_CREDITCARD_TEXT;

            case '3d-dankort': return MODULE_PAYMENT_QUICKPAY_ADVANCED_DANKORT_3D_TEXT;
            case '3d-jcb': return MODULE_PAYMENT_QUICKPAY_ADVANCED_JCB_3D_TEXT;
            case '3d-visa': return MODULE_PAYMENT_QUICKPAY_ADVANCED_VISA_3D_TEXT;
            case '3d-visa-dk': return MODULE_PAYMENT_QUICKPAY_ADVANCED_VISA_DK_3D_TEXT;
            case '3d-visa-electron': return MODULE_PAYMENT_QUICKPAY_ADVANCED_VISA_ELECTRON_3D_TEXT;
            case '3d-visa-electron-dk': return MODULE_PAYMENT_QUICKPAY_ADVANCED_VISA_ELECTRON_DK_3D_TEXT;
            case '3d-visa-debet': return MODULE_PAYMENT_QUICKPAY_ADVANCED_VISA_DEBET_3D_TEXT;
            case '3d-visa-debet-dk': return MODULE_PAYMENT_QUICKPAY_ADVANCED_VISA_DEBET_DK_3D_TEXT;
            case '3d-maestro': return MODULE_PAYMENT_QUICKPAY_ADVANCED_MAESTRO_3D_TEXT;
            case '3d-maestro-dk': return MODULE_PAYMENT_QUICKPAY_ADVANCED_MAESTRO_DK_3D_TEXT;
            case '3d-mastercard': return MODULE_PAYMENT_QUICKPAY_ADVANCED_MASTERCARD_3D_TEXT;
            case '3d-mastercard-dk': return MODULE_PAYMENT_QUICKPAY_ADVANCED_MASTERCARD_DK_3D_TEXT;
            case '3d-mastercard-debet': return MODULE_PAYMENT_QUICKPAY_ADVANCED_MASTERCARD_DEBET_3D_TEXT;
            case '3d-mastercard-debet-dk': return MODULE_PAYMENT_QUICKPAY_ADVANCED_MASTERCARD_DEBET_DK_3D_TEXT;
            case '3d-creditcard': return MODULE_PAYMENT_QUICKPAY_ADVANCED_CREDITCARD_3D_TEXT;
            case 'mastercard': return MODULE_PAYMENT_QUICKPAY_ADVANCED_MASTERCARD_TEXT;
            case 'mastercard-dk': return MODULE_PAYMENT_QUICKPAY_ADVANCED_MASTERCARD_DK_TEXT;
            case 'mastercard-debet': return MODULE_PAYMENT_QUICKPAY_ADVANCED_MASTERCARD_DEBET_TEXT;
            case 'mastercard-debet-dk': return MODULE_PAYMENT_QUICKPAY_ADVANCED_MASTERCARD_DEBET_DK_TEXT;
            case 'american-express': return MODULE_PAYMENT_QUICKPAY_ADVANCED_AMERICAN_EXPRESS_TEXT;
            case 'american-express-dk': return MODULE_PAYMENT_QUICKPAY_ADVANCED_AMERICAN_EXPRESS_DK_TEXT;
            case 'dankort': return MODULE_PAYMENT_QUICKPAY_ADVANCED_DANKORT_TEXT;
            case 'diners': return MODULE_PAYMENT_QUICKPAY_ADVANCED_DINERS_TEXT;
            case 'diners-dk': return MODULE_PAYMENT_QUICKPAY_ADVANCED_DINERS_DK_TEXT;
            case 'jcb': return MODULE_PAYMENT_QUICKPAY_ADVANCED_JCB_TEXT;
            case 'visa': return MODULE_PAYMENT_QUICKPAY_ADVANCED_VISA_TEXT;
            case 'visa-dk': return MODULE_PAYMENT_QUICKPAY_ADVANCED_VISA_DK_TEXT;
            case 'visa-electron': return MODULE_PAYMENT_QUICKPAY_ADVANCED_VISA_ELECTRON_TEXT;
            case 'visa-electron-dk': return MODULE_PAYMENT_QUICKPAY_ADVANCED_VISA_ELECTRON_DK_TEXT;
            case 'viabill': return MODULE_PAYMENT_QUICKPAY_ADVANCED_VIABILL_TEXT;
            case 'fbg1886': return MODULE_PAYMENT_QUICKPAY_ADVANCED_FBG1886_TEXT;
            case 'paypal': return MODULE_PAYMENT_QUICKPAY_ADVANCED_PAYPAL_TEXT;
            case 'sofort': return MODULE_PAYMENT_QUICKPAY_ADVANCED_SOFORT_TEXT;
            case 'mobilepay': return MODULE_PAYMENT_QUICKPAY_ADVANCED_MOBILEPAY_TEXT;
            case 'bitcoin': return MODULE_PAYMENT_QUICKPAY_ADVANCED_BITCOIN_TEXT;
            case 'swish': return MODULE_PAYMENT_QUICKPAY_ADVANCED_SWISH_TEXT;
            case 'trustly': return MODULE_PAYMENT_QUICKPAY_ADVANCED_TRUSTLY_TEXT;
            case 'klarna': return MODULE_PAYMENT_QUICKPAY_ADVANCED_KLARNA_TEXT;

            case 'maestro': return MODULE_PAYMENT_QUICKPAY_ADVANCED_MAESTRO_TEXT;
            case 'ideal': return MODULE_PAYMENT_QUICKPAY_ADVANCED_IDEAL_TEXT;
            case 'paysafecard': return MODULE_PAYMENT_QUICKPAY_ADVANCED_PAYSAFECARD_TEXT;
            case 'resurs': return MODULE_PAYMENT_QUICKPAY_ADVANCED_RESURS_TEXT;
            case 'vipps': return MODULE_PAYMENT_QUICKPAY_ADVANCED_VIPPS_TEXT;

            // case 'danske-dk': return MODULE_PAYMENT_QUICKPAY_ADVANCED_DANSKE_DK_TEXT;
            // case 'edankort': return MODULE_PAYMENT_QUICKPAY_ADVANCED_EDANKORT_TEXT;
            // case 'nordea-dk': return MODULE_PAYMENT_QUICKPAY_ADVANCED_NORDEA_DK_TEXT;
            // case 'viabill':  return MODULE_PAYMENT_QUICKPAY_ADVANCED_viabill_DESCRIPTION;
            // case 'paii': return MODULE_PAYMENT_QUICKPAY_ADVANCED_PAII_TEXT;
        }
        return '';
    }


    protected function sign($params, $api_key) {
        ksort($params);
        $base = implode(" ", $params);

        return hash_hmac("sha256", $base, $api_key);
    }


    private function get_quickpay_order_status($order_id,$mode="") {
        $api= new QuickpayApi();

        $api->setOptions(MODULE_PAYMENT_QUICKPAY_ADVANCED_USERAPIKEY);

        try {
            $api->mode = ($mode=="" ? "payments?order_id=" : "subscriptions?order_id=");

            // Commit the status request, checking valid transaction id
            $st = $api->status(MODULE_PAYMENT_QUICKPAY_ADVANCED_ORDERPREFIX.sprintf('%04d', $order_id));
            $eval = array();
            if(isset($st[0]) && $st[0]["id"]){
                $eval["oid"] = str_replace(MODULE_PAYMENT_QUICKPAY_ADVANCED_ORDERPREFIX,"", $st[0]["order_id"]);
                $eval["qid"] = $st[0]["id"];
            }else{
                $eval["oid"] = null;
                $eval["qid"] = null;
            }

        } catch (Exception $e) {
            $eval = 'QuickPay Status: ';
            // An error occured with the status request
            $eval .= 'Problem: ' . $this->json_message_front($e->getMessage()) ;
            //  tep_redirect(tep_href_link(FILENAME_CHECKOUT_PAYMENT, 'payment_error=' . $this->code, 'SSL'));
        }

        return $eval;
    }


    private function json_message_front($input){

        $dec = json_decode($input,true);

        $message= $dec["message"];

        return $message;
    }
}

/** Display logos in the admin panel in view state */
function show_logos($text) {
    $w = 55;
    $h = 'auto';
    $output = '';

    if ( !empty($text) ) {
        $output = '<ul style="list-style-type: none; margin: 0; padding: 5px; margin-bottom: 10px;">';

        $options = explode(';', $text);
        foreach ($options as $optionc) {
            $iconc = "";
            if(file_exists(DIR_FS_CATALOG . DIR_WS_ICONS . $optionc . ".png")){
                $iconc = DIR_WS_CATALOG_IMAGES . 'icons/'.$optionc.".png";
            }elseif(file_exists(DIR_FS_CATALOG . DIR_WS_IMAGES . 'icons/'.$optionc.".jpg")){
                $iconc = DIR_WS_CATALOG_IMAGES . 'icons/'.$optionc.".jpg";
            }elseif(file_exists(DIR_FS_CATALOG . DIR_WS_IMAGES . 'icons/'.$optionc.".gif")){
                $iconc = DIR_WS_CATALOG_IMAGES . 'icons/'.$optionc.".gif";
            }

            if(strlen($iconc))
                $output .= '<li style="padding: 2px;">' . tep_image($iconc, $optionc , $w, $h) . '</li>';
          }
          $output .= '</ul>';
    }
    return $output;
}

/** Display logos in the admin panel in edit state */
function edit_logos($values, $key) {
    $w = 55;
    $h = 'auto';
    /** Scan images directory for logos */
    $files_array = array();
    if ( $dir = @dir(DIR_FS_CATALOG . DIR_WS_ICONS) ) {
        while ( $file = $dir->read() ) {
            /** Check if image is valid */
            if ( !is_dir(DIR_FS_CATALOG . DIR_WS_ICONS . $file ) && in_array(explode('.',$file)[0],MODULE_AVAILABLE_CREDITCARDS)) {
                if (in_array(substr($file, strrpos($file, '.')+1), array('gif', 'jpg', 'png')) ) {
                    $files_array[] = $file;
                }
            }
        }
        sort($files_array);
        $dir->close();
    }

    /** Display logos to be shown */
    $values_array = !empty($values) ? explode(';', $values) : array();
    $output = '<h3>' . MODULE_PAYMENT_QUICKPAY_CARD_LOGOS_SHOWN_CARDS . '</h3>' .
              '<ul id="ca_logos" style="list-style-type: none; margin: 0; padding: 5px; margin-bottom: 10px;">';

    foreach ($values_array as $optionc) {
        $iconc = "";
        if(file_exists(DIR_FS_CATALOG . DIR_WS_ICONS . $optionc.".png")){
            $iconc = DIR_WS_CATALOG_IMAGES . 'icons/'.$optionc.".png";
        }elseif(file_exists(DIR_FS_CATALOG . DIR_WS_ICONS . $optionc.".jpg")){
            $iconc = DIR_WS_CATALOG_IMAGES . 'icons/'.$optionc.".jpg";
        }elseif(file_exists(DIR_FS_CATALOG . DIR_WS_ICONS . $optionc.".gif")){
            $iconc = DIR_WS_CATALOG_IMAGES . 'icons/'.$optionc.".gif";
        }

        if(strlen($iconc))
            $output .= '<li style="padding: 2px;">' . tep_image($iconc, $optionc, $w, $h) . tep_draw_hidden_field('bm_card_acceptance_logos[]', $optionc) . '</li>';
    }

    $output .= '</ul>';

    /** Display available logos */
    $output .= '<h3>' . MODULE_PAYMENT_QUICKPAY_CARD_LOGOS_NEW_CARDS . '</h3><ul id="new_ca_logos" style="list-style-type: none; margin: 0; padding: 5px; margin-bottom: 10px;">';
    foreach ($files_array as $file) {
        /** Check if logo is not already displayed in "Available list" */
        if ( !in_array(explode(".",$file)[0], $values_array) ) {
            $output .= '<li style="padding: 2px;">' . tep_image(DIR_WS_CATALOG_IMAGES . 'icons/' . $file, explode(".",$file)[0], $w, $h) . tep_draw_hidden_field('bm_card_acceptance_logos[]', explode(".",$file)[0]) . '</li>';
        }
    }

    $output .= '</ul>';

    $output .= tep_draw_hidden_field('configuration[' . $key . ']', '', 'id="ca_logo_cards"');

    $drag_here_li = '<li id="caLogoEmpty" style="background-color: #fcf8e3; border: 1px #faedd0 solid; color: #a67d57; padding: 5px;">' . addslashes(MODULE_PAYMENT_QUICKPAY_CARD_LOGOS_DRAG_HERE) . '</li>';

    /** Drag and Drop logic */
    $output .= <<<EOD
              <script>
                  $(function() {
                      var drag_here_li = '{$drag_here_li}';
                      if ( $('#ca_logos li').length < 1 ) {
                          $('#ca_logos').append(drag_here_li);
                      }

                      $('#ca_logos').sortable({
                          connectWith: '#new_ca_logos',
                          items: 'li:not("#caLogoEmpty")',
                          stop: function (event, ui) {
                              if ( $('#ca_logos li').length < 1 ) {
                                  $('#ca_logos').append(drag_here_li);
                              } else if ( $('#caLogoEmpty').length > 0 ) {
                                  $('#caLogoEmpty').remove();
                              }
                          }
                      });

                      $('#new_ca_logos').sortable({
                          connectWith: '#ca_logos',
                          stop: function (event, ui) {
                              if ( $('#ca_logos li').length < 1 ) {
                                  $('#ca_logos').append(drag_here_li);
                              } else if ( $('#caLogoEmpty').length > 0 ) {
                                  $('#caLogoEmpty').remove();
                              }
                          }
                      });

                      $('#ca_logos, #new_ca_logos').disableSelection();

                      $('form[name="modules"]').submit(function(event) {
                          var ca_selected_cards = '';

                          if ( $('#ca_logos li').length > 0 ) {
                              $('#ca_logos li input[name="bm_card_acceptance_logos[]"]').each(function() {
                                  ca_selected_cards += $(this).attr('value') + ';';
                              });
                          }

                        if (ca_selected_cards.length > 0) {
                            ca_selected_cards = ca_selected_cards.substring(0, ca_selected_cards.length - 1);
                        }

                        $('#ca_logo_cards').val(ca_selected_cards);
                      });
                  });
              </script>
EOD;
    return $output;
}

function myEach(&$arr) {
    $key = key($arr);
    $result = ($key === null) ? false : [$key, current($arr), 'key' => $key, 'value' => current($arr)];
    next($arr);
    return $result;
}

?>