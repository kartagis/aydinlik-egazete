<?php

namespace Drupal\aydinlik_batch\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\User;

/**
 * Implements a Batch example Form.
 */
class AydinlikBatchForm extends FormBase {

  /**
   * {@inheritdoc}.
   */
  public function getFormId() {
    return 'aydinlikbatchform';
  }

  /**
   * {@inheritdoc}.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
  
    $form['subscriptionCodes'] = [
      '#type' => 'textarea', 
      '#title' => 'Subscription Codes',
      '#size' => 1000,
      '#description' => t('Enter the line separated subscription codes'),
      '#required' => TRUE,  
    ];

    $form['submit_button'] = [
      '#type' => 'submit',
      '#value' => $this->t('Check subscriptions'),
    ]; 

    return $form;
  }
   

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
  
  }

  
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $ref_codes = explode("\n",$form_state->getValue('subscriptionCodes'));

    
    $batch = array(
      'title' => t('Verifying subscirptions...'),
      'init_message'     => t('Processing'),
      'operations' => [],
      'progress_message' => t('Processed @current out of @total.'),
      'error_message'    => t('An error occurred during processing'),
      'finished' => '\Drupal\aydinlik_batch\Form\AydinlikBatchForm::batchFinished',
    );
    foreach ($ref_codes as $ref_code) {
      $ref_code = trim($ref_code);
      $batch['operations'][] = ['Drupal\aydinlik_batch\Form\AydinlikBatchForm::subscription_check', [$ref_code]];
    }
      batch_set($batch);
      \Drupal::messenger()->addMessage('Abonelikler kontrol edildi. Yanlış abonelikler iptal edildi.');
    }
    public static function subscription_check($ref_code) {
      $request = new \Iyzipay\Request\Subscription\SubscriptionDetailsRequest();
      $request->setSubscriptionReferenceCode($ref_code);
      $result = \Iyzipay\Model\Subscription\SubscriptionDetails::retrieve($request,\Drupal\iyzipay\Config::options());
      if ($result->getStatus() == 'success') {
        $email = $result->getCustomerEmail();
        $productName = $result->getProductName();
  
        $users = User::loadMultiple();
        foreach ($users as $user) {
          $umail = $user->mail->value;
          if ($email == $umail) {
  
            if ($user->field_abonelik_suresi->target_id == '1354') {
              $sType = 'Aylık Abonelik';
            }
            if ($user->field_abonelik_suresi->target_id == '1360') {
              $sType = 'Aylık Abonelik - Öğrenci';
            }
            if ($user->field_abonelik_suresi->target_id == '1364') {
              $sType = 'Yıllık Abonelik - Avrupa';
            }
            if ($user->field_abonelik_suresi->target_id == '1365') {
              $sType = 'Yıllık Abonelik - Avrupa Dışı';
            }
            if ($user->field_abonelik_suresi->target_id == '1359') {
              $sType = 'Yıllık Abonelik - Öğrenci';
            }
            if ($user->field_abonelik_suresi->target_id == '1355') {
              $sType = '3 Aylık Abonelik';
            }
            if ($user->field_abonelik_suresi->target_id == '1356') {
              $sType = '6 Aylık Abonelik';
            }
            if ($user->field_abonelik_suresi->target_id == '1358') {
              $sType = 'Yıllık Abonelik';
            }
            if ($productName != $sType) {
              $name = $user->field_adiniz->value;
              $surname = $user->field_soyadiniz->value;
              $ns = $name . ' ' . $surname;
              $request = new \Iyzipay\Request\Subscription\SubscriptionCancelRequest();
              $request->setLocale("tr");
              $request->setSubscriptionReferenceCode($ref_code);
              $result = \Iyzipay\Model\Subscription\SubscriptionCancel::cancel($request, \Drupal\iyzipay\Config::options());
              $message = $ns . ' kullanıcısının ' . $ref_code . ' referans kodlu yanlış aboneliği iptal edilmiştir.';
              \Drupal::messenger()->addWarning($message);
              \Drupal::logger('aydinlik_batch')->notice($message);
            }
          }
        }
      }
    }
  }