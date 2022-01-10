<?php

namespace Drupal\Tests\commerce_authnet\Kernel;

use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests config upgrades / post updates.
 *
 * @group commerce_authnet
 */
class UpgradeConfigTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'profile',
    'commerce',
    'commerce_price',
    'commerce_payment',
    'commerce_order',
    'commerce_authnet',
  ];

  /**
   * Tests upgrading from c65e90993f8b4919ed427043ace4e960202092e0.
   */
  public function testPreAcceptJsUpgrade() {
    $this->installFixture(__DIR__ . '/../../fixtures/authorizenet-pre-acceptjs.php.gz');

    $gateways = PaymentGateway::loadMultiple();
    $this->assertCount(1, $gateways);

    $authnet_post_updates = $this->container->get('update.post_update_registry')->getModuleUpdateFunctions('commerce_authnet');
    $this->assertCount(2, $authnet_post_updates);
    foreach ($authnet_post_updates as $authnet_post_update) {
      $sandbox = [];
      $authnet_post_update($sandbox);
    }

    /** @var \Drupal\commerce_payment\Entity\PaymentGateway $gateway */
    $gateway = PaymentGateway::load('authorizenet_pre_acceptjs');
    $this->assertNotNull($gateway);
    $this->assertEquals('authorizenet_acceptjs', $gateway->getPluginId());

    $gateway_configuration = $gateway->getPluginConfiguration();
    $this->assertEquals([
      'mode' => 'test',
      'api_login' => 'TESTING_WITH_FAKE_LOGIN_ID',
      'transaction_key' => 'TESTING_WITH_FAKE_TRANSACTION_KEY',
      'client_key' => '',
      'display_label' => 'Authorize.net',
      'payment_method_types' => ['credit_card'],
      'cca_status' => FALSE,
      'cca_api_id' => '',
      'cca_org_unit_id' => '',
      'cca_api_key' => '',
      'collect_billing_information' => TRUE,
    ], $gateway_configuration);
  }

  /**
   * Tests the client key message.
   */
  public function testClientKeyMessage() {
    $this->installFixture(__DIR__ . '/../../fixtures/authorizenet-pre-acceptjs.php.gz');
    $results = [];
    $authnet_post_updates = $this->container->get('update.post_update_registry')->getModuleUpdateFunctions('commerce_authnet');
    $this->assertCount(2, $authnet_post_updates);
    foreach ($authnet_post_updates as $authnet_post_update) {
      $sandbox = [];
      $results[] = $authnet_post_update($sandbox);
    }

    $this->assertCount(2, $results);

    /** @var \Drupal\commerce_payment\Entity\PaymentGateway $gateway */
    $gateway = PaymentGateway::load('authorizenet_pre_acceptjs');
    $this->assertEquals(
      t('Please provide a client key for %labels. It is required to continue accepting payments.', [
        '%labels' => implode(', ', [$gateway->label()]),
      ]),
      $results[1]
    );

  }

  /**
   * Installs the test fixture.
   *
   * @param string $path
   *   The path to the db-tools test fixture.
   *
   * @throws \Exception
   */
  protected function installFixture($path) {
    $connection = $this->container->get('database');
    $schema = $connection->schema();
    $tables = $schema->findTables('%');
    foreach ($tables as $table) {
      $schema->dropTable($table);
    }
    $this->loadFixture($path);
  }

  /**
   * Loads a database fixture into the source database connection.
   *
   * @param string $path
   *   Path to the dump file.
   */
  protected function loadFixture($path) {
    if (substr($path, -3) == '.gz') {
      $path = 'compress.zlib://' . $path;
    }
    require $path;
  }

}
