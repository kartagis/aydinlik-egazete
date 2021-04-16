<?php

namespace Drupal\aydinlik\EventSubscriber;

use Drupal\Core\Session\AccountInterface;
use Drupal\user\Entity\User;
use Drupal\commerce_order\Event\OrderEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Iyzipay\Model\ThreedsPayment;
use Iyzipay\Options;
use Iyzipay\Model\Locale;
use Iyzipay\Request\CreateThreedsPaymentRequest;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Entity\Query\QueryFactory;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_order\Entity\Order;
use Iyzipay\Request\RetrieveInstallmentInfoRequest;
use Iyzipay\Model\InstallmentInfo;

class OrderPaidSubscriber implements EventSubscriberInterface {

  /**
   * Current user account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $current_user;
  protected $entityQuery;
  protected $messenger;
  private $entity;

  /**
   * OrderPaidSubscriber constructor.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   Current user account.
   */
  public function __construct(AccountInterface $current_user) {
    $this->current_user = $current_user;
  }


  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events = ['commerce_order.order.paid' => 'onPaid'];
    return $events;
  }

  /**
   * Adds the necessary role to the customer upon successful payment
   *
   * @param \Drupal\commerce_order\Event\OrderEvent $event
   *   The event.
   */
  public function onPaid(OrderEvent $event) {
    $order = $event->getOrder();
    $this->current_user = User::load($order->uid[0]->target_id);
    /*if ($order->getState()->value === 'completed') {
      $this->current_user->addRole('abone');
      $this->current_user->save();
    }
    else {
      if ($this->current_user-hasRole('abone')) {
        $this->current_user->removeRole('abone');
        $this->current_user->save();
      }
    }*/
    if ($order->getState()->value === 'completed') {
      $dateTime = \DateTime::createFromFormat('Y-m-d',date('Y-m-d'));
      $today = $dateTime->format('Y-m-d');
      $this->entity;
      // @var \Drupal\commerce_order\Entity\OrderInterface $order
      $order_price = $order->total_price->number;
      $order_items = $order->getItems();
      $order_item = reset($order_items);
      $product_variation = $order_item->getPurchasedEntity();
      $sku = $product_variation->getSku();
      if (str_contains ($sku, 'aylik')) {
        $sku_substr = substr($sku, 0, 14);
      }
      elseif (str_contains ($sku, 'yillik')) {
        $sku_substr = substr($sku, 0, 15);   
      }
      $from = ["aylik", "yillik", "-"];
      $to = ["Aylık", "Yıllık", " "];
      $name = ucwords(str_replace($from, $to, $sku_substr));
      $epaper_subscription = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['name' => 'E-Gazete Aboneliği']);
      $subscription_duration = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['name' => $name]);
      $this->current_user->field_abonelik_suresi[0] = ['target_id' => reset($subscription_duration)->id()];
      switch ($sku_substr) {
        case 'aylik-abonelik':
        case 'aylik-abonelik-ogrenci':
          if (!empty($this->current_user->field_abonelik_turu)) {
            unset($this->current_user->field_abonelik_turu);
          }
          if ($this->current_user->field_abonelik_baslangic_tarihi->value == NULL) {
            $this->current_user->field_abonelik_baslangic_tarihi->value = $today;
          }
          $this->current_user->field_abonelik_bitis_tarihi->value = date('Y-m-d', strtotime('+1 month'));
          $this->current_user->field_abonelik_turu[] = ['target_id' => reset($epaper_subscription)->id()];
          $this->current_user->save();
          break;
        case 'yillik-abonelik':
        case 'yillik-abonelik-ogrenci':
          if (!empty($this->current_user->field_abonelik_turu)) {
            unset($this->current_user->field_abonelik_turu);
          }
          if ($this->current_user->field_abonelik_baslangic_tarihi->value == NULL) {
            $this->current_user->field_abonelik_baslangic_tarihi->value = $today;
          }
          $this->current_user->field_abonelik_bitis_tarihi->value = date('Y-m-d', strtotime('+1 year'));
          $earchive_subscription = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['name' => 'E-Arşiv Aboneliği']);
          $this->current_user->field_abonelik_turu[] = ['target_id' => reset($epaper_subscription)->id()];
          $this->current_user->field_abonelik_turu[] = ['target_id' => reset($earchive_subscription)->id()];
          $this->current_user->save();
        default:
          # code...
          break;
      }

      $config = \Drupal::configFactory()->getEditable('iyzipay.settings');
      $bin_number = substr($config->get('number'),0,6);
      # create request class
      $request = new \Iyzipay\Request\RetrieveInstallmentInfoRequest();
      $request->setLocale(\Iyzipay\Model\Locale::TR);
      $request->setConversationId($order_id);
      $request->setBinNumber($bin_number);
      $request->setPrice("$order_price");

      # make request
      $installmentInfo = \Iyzipay\Model\InstallmentInfo::retrieve($request, \Drupal\iyzipay\Config::options());

      $cardType = $installmentInfo->getInstallmentDetails()[0]->getCardType();
      if ($cardType != NULL) {
        if (!str_contains($cardType, 'DEBIT')) {
          $orders = Order::loadMultiple();
          $order = end($orders);
          $order_items = $order->getItems();
          $order_item = reset($order_items);
          $product_variation = $order_item->getPurchasedEntity();
          $sku_field = $product_variation->field_sku->value;
          $user = User::load(\Drupal::currentUser()->id());
          $ad_soyad = $user->field_adiniz_soyadiniz->value;
          $parts = explode(' ', $ad_soyad);
          $last = array_pop($parts);
          $parts = array(implode(' ', $parts), $last);
          $request = new \Iyzipay\Request\Subscription\SubscriptionCreateRequest();
          $request->setLocale("tr");
          $request->setConversationId($order_id);
          $request->setPricingPlanReferenceCode($sku_field);
          $request->setSubscriptionInitialStatus("PENDING");
          $paymentCard = new \Iyzipay\Model\PaymentCard();
          $paymentCard->setCardHolderName($config->get('holder_name'));
          $paymentCard->setCardNumber($config->get('number'));
          $paymentCard->setExpireMonth($config->get('expiration_month'));
          $paymentCard->setExpireYear($config->get('expiration_year'));
          $paymentCard->setCvc($config->get('cvc'));
          $request->setPaymentCard($paymentCard);
          $customer = new \Iyzipay\Model\Customer();
          $customer->setName($parts[0]);
          $customer->setSurname($parts[1]);
          $gsm_number = $user->field_telefon->value;
          $num='';
          $gsm_st='';
          if (strlen($gsm_number) >= 10 && $user->field_adres->country_code == 'TR'){
            $gsm_lastten = substr($gsm_number, -10);
            $gsm_valid = '+90'.$gsm_lastten;
          }
          else {
            $gsm_operator_number = '+905';
            $gsm_nums[]= array(0, 3, 4, 5);
            $sayi = rand(0,3);
            $gsm_st = $gsm_operator_number.$gsm_nums[0][$sayi];
            if (str_contains($gsm_st, '53')) {
              for ($i=0; $i<8; $i++) {
                $sayi1 = rand(0,9);
                $num = $num.$sayi1;
              }
              $gsm_valid = $gsm_st.$num;
            }
            if (str_contains($gsm_st, '54')) {
              for ($i=0; $i<8; $i++) {
                  $sayi1 = rand(1,6);
                  $num = $num.$sayi1;
              }
              $gsm_valid = $gsm_st.$num;
            }
            if (str_contains($gsm_st, '50')) {
              for ($i=0; $i<8; $i++) {
                  $sayi1 = rand(5,7);
                  $num = $num.$sayi1;
              }
              $gsm_valid = $gsm_st.$num;
            }
            if (str_contains($gsm_st, '55')) {
              $gsm_st=$gsm_st.'5';
              for ($i=0; $i<7; $i++) {
                $sayi1 = rand(1,9);
                $num = $num.$sayi1;
              }
              $gsm_valid = $gsm_st.$num;
            }
          }
          $customer->setGsmNumber($gsm_valid);
          $customer->setEmail($user->getEmail());
          $customer->setIdentityNumber(($user->field_tc->value)?: '11111111110');
          $customer->setShippingContactName($ad_soyad);
          $customer->setShippingCity(($user->field_adres->administrative_area)?:'İstanbul');
          $customer->setShippingCountry(($user->field_adres->country_code)?:'TR');
          $customer->setShippingAddress(($user->field_adres->address_line1)?:'Beyoğlu');
          $customer->setShippingZipCode(($user->field_adres->postal_code)?:'34100');
          $customer->setBillingContactName($ad_soyad);
          $customer->setBillingCity(($user->field_adres->administrative_area)?:'İstanbul');
          $customer->setBillingCountry(($user->field_adres->country_code)?:'TR');
          $customer->setBillingAddress(($user->field_adres->address_line1)?:'Beyoğlu');
          $customer->setBillingZipCode(($user->field_adres->postal_code)?:'34100');
          $request->setCustomer($customer);
          $result = \Iyzipay\Model\Subscription\SubscriptionCreate::create($request,\Drupal\iyzipay\Config::options());
          $rs = $result->getStatus(); //$rs is the result_status.
          $src = $result->getReferenceCode(); //$src is Subscription reference code.
          /**
           * If result is success activate subscription
          */
          if ($rs == 'success') {
              $request = new \Iyzipay\Request\Subscription\SubscriptionActivateRequest();
              $request->setLocale("tr");
              $request->setConversationId($order->id());
              $request->setSubscriptionReferenceCode($src);
              $result = \Iyzipay\Model\Subscription\SubscriptionActivate::update($request,\Drupal\iyzipay\Config::options());
          }
          //Retrive subscription details and saving field_abonelik_durumu
          $request = new \Iyzipay\Request\Subscription\SubscriptionDetailsRequest();
          $request->setSubscriptionReferenceCode($src);
          $result = \Iyzipay\Model\Subscription\SubscriptionDetails::retrieve($request,\Drupal\iyzipay\Config::options());
          $ss = $result->getSubscriptionStatus(); //$ss is subscription status.
          if ($ss == 'ACTIVE') {
            $this->current_user->field_abonelik_durumu->value = 'Aktif';
            $this->current_user->field_abonelik_referans_kodu->value = $src;
            $this->current_user->addRole('abone');
            $this->current_user->save();
          }
          else {
            $this->current_user->field_abonelik_durumu->value = 'Beklemede';
            $this->current_user->save();
          }

          $config->delete();
          $order->field_kart_turu->value = 'Kredi Kartı';
          $order->save();
          if ($order->total_paid->number != $order->total_price->number) {
            \Drupal::messenger()->addError($this->t('Your payment has failed and subscription was not created.'));
          }
          else {
            \Drupal::messenger()->addMessage($this->t('Your payment was successfully received and subscription was created.'));
          }
          // Delete the last payment method local entity.
          $payment = Payment::loadMultiple();
          $last_payment = end($payment);
          $payment_method = $last_payment->getPaymentMethod();
          $payment_method->delete();
          return new TrustedRedirectResponse('/user/');
        }
        else {
          $config->delete();
          $order->field_kart_turu->value = 'Banka Kartı';
          $order->save();
          $this->current_user->field_abonelik_durumu->value = 'Banka Kartı ile Abonelik';
          $this->current_user->save();
          if ($order->total_paid->number != $order->total_price->number) {
            \Drupal::messenger()->addError($this->t('Your payment was failed and subscription was not created.'));
          }
          else {
            \Drupal::messenger()->addMessage($this->t('Your payment card is a debit card. Subsciption can not be created.'));
            \Drupal::messenger()->addMessage($this->t('Your payment was successfully received.'));
            $this->current_user->addRole('abone');
            $this->current_user->save();
          }
          // Delete the last payment method local entity.
          $payment = Payment::loadMultiple();
          $last_payment = end($payment);
          $payment_method = $last_payment->getPaymentMethod();
          $payment_method->delete();
          return new TrustedRedirectResponse('/user/');
        }
      }
      else{
        try {
          $orders = Order::loadMultiple();
          $order = end($orders);
          $order_items = $order->getItems();
          $order_item = reset($order_items);
          $product_variation = $order_item->getPurchasedEntity();
          $sku_field = $product_variation->field_sku->value;
          $user = User::load(\Drupal::currentUser()->id());
          $ad_soyad = $user->field_adiniz_soyadiniz->value;
          $parts = explode(' ', $ad_soyad);
          $last = array_pop($parts);
          $parts = array(implode(' ', $parts), $last);
          $request = new \Iyzipay\Request\Subscription\SubscriptionCreateRequest();
          $request->setLocale("tr");
          $request->setConversationId($order_id);
          $request->setPricingPlanReferenceCode($sku_field);
          $request->setSubscriptionInitialStatus("PENDING");
          $paymentCard = new \Iyzipay\Model\PaymentCard();
          $paymentCard->setCardHolderName($config->get('holder_name'));
          $paymentCard->setCardNumber($config->get('number'));
          $paymentCard->setExpireMonth($config->get('expiration_month'));
          $paymentCard->setExpireYear($config->get('expiration_year'));
          $paymentCard->setCvc($config->get('cvc'));
          $request->setPaymentCard($paymentCard);
          $customer = new \Iyzipay\Model\Customer();
          $customer->setName($parts[0]);
          $customer->setSurname($parts[1]);
          //$customer->setGsmNumber(($user->field_telefon->value)?: '+905555555555');
          //$customer->setGsmNumber('+905555555555');
          $gsm_number = $user->field_telefon->value;
          $num='';
          $gsm_st='';
          if (strlen($gsm_number) >= 10 && $user->field_adres->country_code == 'TR'){
            $gsm_lastten = substr($gsm_number, -10);
            $gsm_valid = '+90'.$gsm_lastten;
          }
          else {
            $gsm_operator_number = '+905';
            $gsm_nums[]= array(0, 3, 4, 5);
            $sayi = rand(0,3);
            $gsm_st = $gsm_operator_number.$gsm_nums[0][$sayi];
            if (str_contains($gsm_st, '53')) {
              for ($i=0; $i<8; $i++) {
                $sayi1 = rand(0,9);
                $num = $num.$sayi1;
              }
              $gsm_valid = $gsm_st.$num;
            }
            if (str_contains($gsm_st, '54')) {
              for ($i=0; $i<8; $i++) {
                  $sayi1 = rand(1,6);
                  $num = $num.$sayi1;
              }
              $gsm_valid = $gsm_st.$num;
            }
            if (str_contains($gsm_st, '50')) {
              for ($i=0; $i<8; $i++) {
                  $sayi1 = rand(5,7);
                  $num = $num.$sayi1;
              }
              $gsm_valid = $gsm_st.$num;
            }
            if (str_contains($gsm_st, '55')) {
              $gsm_st=$gsm_st.'5';
              for ($i=0; $i<7; $i++) {
                $sayi1 = rand(1,9);
                $num = $num.$sayi1;
              }
              $gsm_valid = $gsm_st.$num;
            }
          }
          $customer->setGsmNumber($gsm_valid);
          $customer->setEmail($user->getEmail());
          $customer->setIdentityNumber(($user->field_tc->value)?: '11111111111');
          $customer->setShippingContactName($ad_soyad);
          $customer->setShippingCity(($user->field_adres->administrative_area)?:'İstanbul');
          $customer->setShippingCountry(($user->field_adres->country_code)?:'TR');
          $customer->setShippingAddress(($user->field_adres->address_line1)?:'Beyoğlu');
          $customer->setShippingZipCode(($user->field_adres->postal_code)?:'34100');
          $customer->setBillingContactName($ad_soyad);
          $customer->setBillingCity(($user->field_adres->administrative_area)?:'İstanbul');
          $customer->setBillingCountry(($user->field_adres->country_code)?:'TR');
          $customer->setBillingAddress(($user->field_adres->address_line1)?:'Beyoğlu');
          $customer->setBillingZipCode(($user->field_adres->postal_code)?:'34100');
          $request->setCustomer($customer);
          $result = \Iyzipay\Model\Subscription\SubscriptionCreate::create($request,\Drupal\iyzipay\Config::options());
          $rs = $result->getStatus(); //$rs is the result status.
          $src = $result->getReferenceCode(); //$src is subscription reference code.
          /**
           * If result is success activate subscription
          */
          if ($rs == 'success') {
            $request = new \Iyzipay\Request\Subscription\SubscriptionActivateRequest();
            $request->setLocale("TR");
            $request->setConversationId($order->id());
            $request->setSubscriptionReferenceCode($src);
            $result = \Iyzipay\Model\Subscription\SubscriptionActivate::update($request,\Drupal\iyzipay\Config::options());
          }
          //Retrive subscription details and saving field_abonelik_durumu
          $request = new \Iyzipay\Request\Subscription\SubscriptionDetailsRequest();
          $request->setSubscriptionReferenceCode($src);
          $result = \Iyzipay\Model\Subscription\SubscriptionDetails::retrieve($request,\Drupal\iyzipay\Config::options());
          $ss = $result->getSubscriptionStatus(); //$ss is subscription status.
          if ($ss == 'ACTIVE') {
            $this->current_user->field_abonelik_durumu->value = 'Aktif';
            $this->current_user->field_abonelik_referans_kodu->value = $src;
            $this->current_user->addRole('abone');
            $this->current_user->save();
          }
          else {
            $this->current_user->field_abonelik_durumu->value = 'Beklemede';
            $this->current_user->save();
          }
          
          $config->delete();
          $order->field_kart_turu->value = 'Yabancı Kart';
          $order->save();
          if ($order->total_paid->number != $order->total_price->number) {
            \Drupal::messenger()->addError($this->t('Your payment was failed and subscription was not created.'));
          }
          else {
            \Drupal::messenger()->addMessage($this->t('Your payment was successfully received and subscription was created.'));
          }
          // Delete the last payment method local entity.
          $payment = Payment::loadMultiple();
          $last_payment = end($payment);
          $payment_method = $last_payment->getPaymentMethod();
          $payment_method->delete();
          return new TrustedRedirectResponse('/user/');   
        }
        catch (Exception $e) {
          $config->delete();
          \Drupal::messenger()->addError($this->t('Your payment card is not a credit card. Subsciption can not be created, please contact us.'));
          // Delete the last payment method local entity.
          $payment = Payment::loadMultiple();
          $last_payment = end($payment);
          $payment_method = $last_payment->getPaymentMethod();
          $payment_method->delete();
          return new TrustedRedirectResponse('/user/');
        }
      }
    }
  }

}