<?php
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Integration\PaymentMethods;

use MultiSafepay\Shopware6\API\MspClient;
use MultiSafepay\Shopware6\Handlers\MultiSafepayPaymentHandler;
use MultiSafepay\Shopware6\Helper\ApiHelper;
use MultiSafepay\Shopware6\Helper\MspHelper;
use MultiSafepay\Shopware6\Service\SettingsService;
use MultiSafepay\Shopware6\Tests\Fixtures\PaymentMethods;
use MultiSafepay\Shopware6\Helper\CheckoutHelper;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders\Transactions;
use MultiSafepay\Shopware6\API\Object\Orders as MspOrders;
use MultiSafepay\Shopware6\PaymentMethods\MultiSafepay;
use MultiSafepay\Shopware6\Tests\Fixtures\Customers;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\Exception\StateMachineStateNotFoundException;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use stdClass;

class MultiSafepayTest extends TestCase
{
    use IntegrationTestBehaviour, Orders, Transactions, Customers, PaymentMethods {
        IntegrationTestBehaviour::getContainer insteadof Transactions;
        IntegrationTestBehaviour::getContainer insteadof Customers;
        IntegrationTestBehaviour::getContainer insteadof PaymentMethods;
        IntegrationTestBehaviour::getContainer insteadof Orders;

        IntegrationTestBehaviour::getKernel insteadof Transactions;
        IntegrationTestBehaviour::getKernel insteadof Customers;
        IntegrationTestBehaviour::getKernel insteadof PaymentMethods;
        IntegrationTestBehaviour::getKernel insteadof Orders;
    }

    private const API_KEY = '11111111111111111111111';
    private const API_ENV = 'test';
    private const ORDER_NUMBER = '12345';
    private $customerRepository;
    private $orderRepository;
    private $context;
    private $orderTransactionRepository;
    private $paymentMethodRepository;
    private $stateMachineRegistry;

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->customerRepository = $this->getContainer()->get('customer.repository');
        /** @var EntityRepositoryInterface $orderRepository */
        $this->orderRepository = $this->getContainer()->get('order.repository');
        $this->orderTransactionRepository = $this->getContainer()->get('order_transaction.repository');
        $this->paymentMethodRepository = $this->getContainer()->get('payment_method.repository');
        $this->stateMachineRegistry = $this->getContainer()->get(StateMachineRegistry::class);
        $this->context = Context::createDefaultContext();
    }

    /**
     * @throws InconsistentCriteriaIdsException
     * @throws AsyncPaymentProcessException
     */
    public function testPayWithConnect(): void
    {
        $paymentMethodId = $this->createPaymentMethod($this->context);
        $customerId = $this->createCustomer($this->context);
        $orderId = $this->createOrder($customerId, $this->context);
        $transactionId = $this->createTransaction($orderId, $paymentMethodId, $this->context);

        /** @var MultiSafepay $multiSafepay */
        $multiSafepay =  $this->setupConnectMockForPay();
        $this->assertNotNull($multiSafepay);

        /** @var AsyncPaymentTransactionStruct $transactionMock */
        $transactionMock = $this->initiateTransactionMock($orderId, $transactionId);
        $this->assertNotNull($transactionMock);
        /** @var RequestDataBag $dataBag */
        $dataBag = $this->initiateDataBagMock();
        $this->assertNotNull($dataBag);
        /** @var SalesChannelContext $salesChannel */
        $salesChannel = $this->initiateSalesChannelContext($customerId, $this->context);
        $this->assertNotNull($salesChannel);

        $multiSafepay->pay($transactionMock, $dataBag, $salesChannel);
    }

    /**
     * @throws InconsistentCriteriaIdsException
     * @throws AsyncPaymentFinalizeException
     * @throws StateMachineStateNotFoundException
     */
    public function testFinalizeWithConnect(): void
    {
        $paymentMethodId = $this->createPaymentMethod($this->context);
        $customerId = $this->createCustomer($this->context);
        $orderId = $this->createOrder($customerId, $this->context);
        $transactionId = $this->createTransaction($orderId, $paymentMethodId, $this->context);

        /** @var MultiSafepay $multiSafepay */
        $multiSafepay =  $this->setupConnectMockForFinalize($this->context);
        $this->assertNotNull($multiSafepay);
        /** @var AsyncPaymentTransactionStruct $transactionMock */
        $transactionMock = $this->initiateTransactionMock($orderId, $transactionId);
        $this->assertNotNull($transactionMock);
        /** @var Request $requestMock */
        $requestMock = $this->initiateRequestMockForCompletedOrder($orderId);
        $this->assertNotNull($requestMock);
        /** @var SalesChannelContext $salesChannelMock */
        $salesChannelMock = $this->initiateSalesChannelContext($customerId, $this->context);
        $this->assertNotNull($salesChannelMock);

        $transaction = $this->getTransaction($transactionId);
        $originalTransactionStateId = $transaction->getStateId();

        $multiSafepay->finalize($transactionMock, $requestMock, $salesChannelMock);

        $transaction = $this->getTransaction($transactionId);
        $changedTransactionStateId = $transaction->getStateId();

        $this->assertNotEquals($originalTransactionStateId, $changedTransactionStateId);
        $this->assertEquals('Paid', $transaction->getStateMachineState()->getName());
    }

    /**
     * @throws CustomerCanceledAsyncPaymentException
     * @throws \Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException
     * @throws \Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException
     */
    public function testCancelFlowForFinalizeShouldThrowException()
    {
        $paymentMethodId = $this->createPaymentMethod($this->context);
        $customerId = $this->createCustomer($this->context);
        $orderId = $this->createOrder($customerId, $this->context);
        $transactionId = $this->createTransaction($orderId, $paymentMethodId, $this->context);

        /** @var MultiSafepay $multiSafepay */
        $multiSafepay =  $this->getMockBuilder(MultiSafepayPaymentHandler::class)
            ->disableOriginalConstructor()
            ->setMethodsExcept(['finalize'])
            ->getMock();

        $this->assertNotNull($multiSafepay);
        /** @var AsyncPaymentTransactionStruct $transactionMock */
        $transactionMock = $this->initiateTransactionMock($orderId, $transactionId);
        $this->assertNotNull($transactionMock);
        /** @var Request $requestMock */
        $requestMock = $this->initiateRequestMockForCancelFlow();
        $this->assertNotNull($requestMock);
        /** @var SalesChannelContext $salesChannelMock */
        $salesChannelMock = $this->initiateSalesChannelContext($customerId, $this->context);
        $this->assertNotNull($salesChannelMock);

        $this->expectException(CustomerCanceledAsyncPaymentException::class);
        $multiSafepay->finalize($transactionMock, $requestMock, $salesChannelMock);
    }

    /**
     * @return MockObject
     */
    private function setupConnectMockForPay(): MockObject
    {
        /** @var ApiHelper $apiHelper */
        $apiHelper = $this->setupApiHelperMockForPay();
        /** @var CheckoutHelper $checkoutHelper */
        $checkoutHelper = $this->getContainer()->get(CheckoutHelper::class);
        /** @var MspHelper $mspHelper */
        $mspHelper = $this->getContainer()->get(MspHelper::class);

        $multiSafepay =  $this->getMockBuilder(MultiSafepayPaymentHandler::class)
            ->setConstructorArgs([
                $apiHelper,
                $checkoutHelper,
                $mspHelper
            ])
            ->setMethodsExcept(['pay', 'finalize'])
            ->getMock();

        return $multiSafepay;
    }

    /**
     * @param Context $context
     * @return MockObject
     */
    private function setupConnectMockForFinalize(Context $context)
    {
        /** @var CheckoutHelper $checkoutHelper */
        $checkoutHelper = $this->getContainer()->get(CheckoutHelper::class);
        /** @var MspHelper $mspHelper */
        $mspHelper = $this->getContainer()->get(MspHelper::class);

        /** @var ApiHelper $apiHelper */
        $apiHelper = $this->setupApiHelperMockForFinalize();


        $multiSafepay =  $this->getMockBuilder(MultiSafepayPaymentHandler::class)
            ->setConstructorArgs([
                $apiHelper,
                $checkoutHelper,
                $mspHelper
            ])
            ->setMethodsExcept(['pay', 'finalize'])
            ->getMock();

        return $multiSafepay;
    }


    /**
     * @param string $customerId
     * @param Context $context
     * @return MockObject
     * @throws InconsistentCriteriaIdsException
     */
    private function initiateSalesChannelContext(string $customerId, Context $context): MockObject
    {
        /** @var CustomerEntity $customer */
        $customer = $this->getCustomer($customerId, $this->context);

        $currencyMock = $this->getMockBuilder(CurrencyEntity::class)
            ->disableOriginalConstructor()
            ->getMock();

        $currencyMock->method('getIsoCode')
            ->willReturn('EUR');

        $salesChannelMock = $this->getMockBuilder(SalesChannelContext::class)
            ->disableOriginalConstructor()
            ->getMock();

        $salesChannelMock->method('getCustomer')
            ->willReturn($customer);

        $salesChannelMock->method('getCurrency')
            ->willReturn($currencyMock);

        $salesChannelMock->method('getContext')
            ->willReturn($context);

        return $salesChannelMock;
    }

    /**
     * @param string $orderId
     * @param string $transactionId
     * @return MockObject
     * @throws InconsistentCriteriaIdsException
     */
    private function initiateTransactionMock(string $orderId, string $transactionId): MockObject
    {
        $OrderTransactionMock = $this->getMockBuilder(OrderTransactionEntity::class)
            ->disableOriginalConstructor()
            ->getMock();

        $OrderTransactionMock->method('getId')
            ->willReturn($transactionId);

        $paymentTransactionMock = $this->getMockBuilder(AsyncPaymentTransactionStruct::class)
            ->disableOriginalConstructor()
            ->getMock();

        $paymentTransactionMock->method('getOrder')
            ->willReturn($this->getOrder($orderId, $this->context));

        $paymentTransactionMock->method('getOrderTransaction')
            ->willReturn($OrderTransactionMock);

        return $paymentTransactionMock;
    }

    /**
     * @return MockObject
     */
    private function initiateDataBagMock(): MockObject
    {
        return $this->createMock(RequestDataBag::class);
    }

    /**
     * @return MockObject
     */
    private function setupApiHelperMockForFinalize(): MockObject
    {
        $multiSafepayOrderData = new stdClass();
        $multiSafepayOrderData->status = 'completed';

        $multiSafepayClientOrdersMock = $this->getMockBuilder(MspOrders::class)
            ->disableOriginalConstructor()
            ->getMock();
        $multiSafepayClientOrdersMock->expects($this->once())
            ->method('get')
            ->willReturn($multiSafepayOrderData);

        $mspClient = $this->getMockBuilder(MspClient::class)
            ->disableOriginalConstructor()
            ->getMock();

        $mspClient->orders = $multiSafepayClientOrdersMock;

        $apiHelperMock = $this->getMockBuilder(ApiHelper::class)
            ->disableOriginalConstructor()
            ->getMock();
        $apiHelperMock->expects($this->once())
            ->method('initializeMultiSafepayClient')
            ->willReturn($mspClient);

        return $apiHelperMock;
    }

    /**
     * @param string $orderId
     * @return MockObject
     */
    private function initiateRequestMockForCompletedOrder(string $orderId): MockObject
    {
        $parameterMock = $this->getMockBuilder(ParameterBag::class)
            ->disableOriginalConstructor()
            ->getMock();

        $parameterMock->expects($this->once())
            ->method('getBoolean')
            ->with($this->equalTo('cancel'))
            ->willReturn(false);

        $parameterMock->expects($this->once())
            ->method('get')
            ->with($this->equalTo('transactionid'))
            ->willReturn(self::ORDER_NUMBER);

        $requestMock = $this->getMockBuilder(Request::class)
            ->getMock();

        $requestMock->query = $parameterMock;

        return $requestMock;
    }

    /**
     * @return MockObject
     */
    private function initiateRequestMockForCancelFlow(): MockObject
    {
        $parameterMock = $this->getMockBuilder(ParameterBag::class)
            ->disableOriginalConstructor()
            ->getMock();

        $parameterMock->expects($this->once())
            ->method('getBoolean')
            ->with($this->equalTo('cancel'))
            ->willReturn(true);

        $parameterMock->expects($this->once())
            ->method('get')
            ->with($this->equalTo('transactionid'))
            ->willReturn(self::ORDER_NUMBER);

        $requestMock = $this->getMockBuilder(Request::class)
            ->getMock();

        $requestMock->query = $parameterMock;

        return $requestMock;
    }

    /**
     * @param string $transactionId
     * @return OrderTransactionEntity
     * @throws InconsistentCriteriaIdsException
     */
    private function getTransaction(string $transactionId): OrderTransactionEntity
    {
        $orderTransactionRepository = $this->getContainer()->get('order_transaction.repository');
        $criteria = new Criteria([$transactionId]);
        /** @var OrderTransactionEntity $transaction */
        $transaction = $orderTransactionRepository->search($criteria, $this->context)->get($transactionId);

        return $transaction;
    }

    /**
     * @return MockObject
     */
    private function setupApiHelperMockForPay(): MockObject
    {
        $settingsServiceMock = $this->getMockBuilder(SettingsService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $settingsServiceMock->expects($this->exactly(2))
            ->method('getSetting')
            ->withConsecutive([$this->equalTo('environment')], [$this->equalTo('apiKey')])
            ->willReturnOnConsecutiveCalls(self::API_ENV, self::API_KEY);

        $mspOrdersMock = $this->getMockBuilder(MspOrders::class)
            ->disableOriginalConstructor()
            ->getMock();

        $postResultMock = new stdClass();
        $postResultMock->order_id = Uuid::randomHex();
        $postResultMock->payment_url = 'https://testpayv2.multisafepay.com';


        $mspOrdersMock->expects($this->once())
            ->method('post')
            ->willReturn($postResultMock);

        $mspOrdersMock->expects($this->once())
            ->method('getPaymentLink')
            ->willReturn($postResultMock->payment_url);

        $mspClient = $this->getMockBuilder(MspClient::class)
            ->disableOriginalConstructor()
            ->getMock();

        $mspClient->orders = $mspOrdersMock;
        $mspClient->orders->success = true;

        $apiHelperMock = $this->getMockBuilder(ApiHelper::class)
            ->setConstructorArgs([$settingsServiceMock, $mspClient])
            ->setMethodsExcept(['initializeMultiSafepayClient'])
            ->getMock();

        return $apiHelperMock;
    }
}
