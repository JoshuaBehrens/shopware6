<?php declare(strict_types=1);
/**
 * Copyright © 2021 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Subscriber;

use Exception;
use MultiSafepay\Shopware6\Handlers\AmericanExpressPaymentHandler;
use MultiSafepay\Shopware6\Handlers\IdealPaymentHandler;
use MultiSafepay\Shopware6\Handlers\MastercardPaymentHandler;
use MultiSafepay\Shopware6\Handlers\VisaPaymentHandler;
use MultiSafepay\Shopware6\Helper\ApiHelper;
use MultiSafepay\Shopware6\Service\SettingsService;
use MultiSafepay\Shopware6\Storefront\Struct\MultiSafepayStruct;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CheckoutConfirmTemplateSubscriber implements EventSubscriberInterface
{
    /**
     * @var ApiHelper
     */
    private $apiHelper;

    /**
     * @var EntityRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var string
     */
    private $shopwareVersion;

    /**
     * @var SettingsService
     */
    private $settingsService;

    /**
     * CheckoutConfirmTemplateSubscriber constructor.
     *
     * @param ApiHelper $apiHelper
     * @param EntityRepositoryInterface $customerRepository
     * @param SettingsService $settingsService
     * @param string $shopwareVersion
     */
    public function __construct(
        ApiHelper $apiHelper,
        EntityRepositoryInterface $customerRepository,
        SettingsService $settingsService,
        string $shopwareVersion
    ) {
        $this->apiHelper = $apiHelper;
        $this->customerRepository = $customerRepository;
        $this->shopwareVersion = $shopwareVersion;
        $this->settingsService = $settingsService;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => 'addMultiSafepayExtension',
        ];
    }

    /**
     * @param CheckoutConfirmPageLoadedEvent $event
     * @throws Exception
     */
    public function addMultiSafepayExtension(CheckoutConfirmPageLoadedEvent $event): void
    {
        $salesChannelContext = $event->getSalesChannelContext();
        $customer = $salesChannelContext->getCustomer();
        $client = $this->apiHelper->initializeMultiSafepayClient($salesChannelContext->getSalesChannel()->getId());
        $struct = new MultiSafepayStruct();
        $issuers = $client->issuers->get();
        $lastUsedIssuer = $customer->getCustomFields()['last_used_issuer'] ?? null;
        $tokens = $client->tokens->get('recurring', $customer->getId());

        if (isset($tokens->tokens)) {
            $tokens = $tokens->tokens;
        }

        $activeToken = $customer->getCustomFields()['active_token'] ?? null;

        switch ($event->getSalesChannelContext()->getPaymentMethod()->getHandlerIdentifier()) {
            case IdealPaymentHandler::class:
                $activeName = $this->getRealIdealName($issuers, $lastUsedIssuer);
                break;
            case VisaPaymentHandler::class:
            case MastercardPaymentHandler::class:
            case AmericanExpressPaymentHandler::class:
                $activeName = $this->getRealTokenizationName(
                    $event->getSalesChannelContext()->getPaymentMethod()->getTranslated()['name'],
                    $tokens,
                    $activeToken
                );
                break;
        }

        $struct->assign([
            'tokenization_enabled' => $this->settingsService->getSetting('tokenization'),
            'tokens' => (array)$tokens,
            'active_token' => $activeToken,
            'issuers' => $issuers,
            'last_used_issuer' => $lastUsedIssuer,
            'shopware_compare' => version_compare($this->shopwareVersion, '6.4.0.0-RC1', '<'),
            'payment_method_name' => $activeName ?? null,
            'tokenization_checked' => $customer->getCustomFields()['tokenization_checked'] ?? null,
            'is_guest' => $customer->getGuest(),
            'current_payment_method_id' => $event->getSalesChannelContext()->getPaymentMethod()->getId(),
        ]);

        $event->getPage()->addExtension(
            MultiSafepayStruct::EXTENSION_NAME,
            $struct
        );
    }

    /**
     * @param array $issuers
     * @param string|null $lastUsedIssuer
     * @return string
     */
    private function getRealIdealName(array $issuers, ?string $lastUsedIssuer): string
    {
        foreach ($issuers as $issuer) {
            if ($issuer->code === $lastUsedIssuer) {
                $issuerName = $issuer->description;

                return 'iDEAL (' . $issuerName . ')';
            }
        }

        return 'iDEAL';
    }

    /**
     * @param string $paymentMethodName
     * @param $tokens
     * @param string|null $activeToken
     * @return string
     */
    private function getRealTokenizationName(string $paymentMethodName, $tokens, string $activeToken = null): string
    {
        foreach ($tokens as $token) {
            if ($token->token === $activeToken) {
                return $paymentMethodName . ' (' . $token->display . ')';
            }
        }

        return $paymentMethodName;
    }
}
