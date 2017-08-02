<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sales\Model\Order\Creditmemo\Sender;

use Magento\Sales\Model\Order\Email\Sender;
use Magento\Sales\Model\Order\Creditmemo\SenderInterface;

/**
 * Email notification sender for Creditmemo.
 * @since 2.1.3
 */
class EmailSender extends Sender implements SenderInterface
{
    /**
     * @var \Magento\Payment\Helper\Data
     * @since 2.1.3
     */
    private $paymentHelper;

    /**
     * @var \Magento\Sales\Model\ResourceModel\Order\Creditmemo
     * @since 2.1.3
     */
    private $creditmemoResource;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     * @since 2.1.3
     */
    private $globalConfig;

    /**
     * @var \Magento\Framework\Event\ManagerInterface
     * @since 2.1.3
     */
    private $eventManager;

    /**
     * @param \Magento\Sales\Model\Order\Email\Container\Template $templateContainer
     * @param \Magento\Sales\Model\Order\Email\Container\CreditmemoIdentity $identityContainer
     * @param \Magento\Sales\Model\Order\Email\SenderBuilderFactory $senderBuilderFactory
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Sales\Model\Order\Address\Renderer $addressRenderer
     * @param \Magento\Payment\Helper\Data $paymentHelper
     * @param \Magento\Sales\Model\ResourceModel\Order\Creditmemo $creditmemoResource
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $globalConfig
     * @param \Magento\Framework\Event\ManagerInterface $eventManager
     * @since 2.1.3
     */
    public function __construct(
        \Magento\Sales\Model\Order\Email\Container\Template $templateContainer,
        \Magento\Sales\Model\Order\Email\Container\CreditmemoIdentity $identityContainer,
        \Magento\Sales\Model\Order\Email\SenderBuilderFactory $senderBuilderFactory,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Sales\Model\Order\Address\Renderer $addressRenderer,
        \Magento\Payment\Helper\Data $paymentHelper,
        \Magento\Sales\Model\ResourceModel\Order\Creditmemo $creditmemoResource,
        \Magento\Framework\App\Config\ScopeConfigInterface $globalConfig,
        \Magento\Framework\Event\ManagerInterface $eventManager
    ) {
        parent::__construct(
            $templateContainer,
            $identityContainer,
            $senderBuilderFactory,
            $logger,
            $addressRenderer
        );

        $this->paymentHelper = $paymentHelper;
        $this->creditmemoResource = $creditmemoResource;
        $this->globalConfig = $globalConfig;
        $this->eventManager = $eventManager;
    }

    /**
     * Sends order creditmemo email to the customer.
     *
     * Email will be sent immediately in two cases:
     *
     * - if asynchronous email sending is disabled in global settings
     * - if $forceSyncMode parameter is set to TRUE
     *
     * Otherwise, email will be sent later during running of
     * corresponding cron job.
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param \Magento\Sales\Api\Data\CreditmemoInterface $creditmemo
     * @param \Magento\Sales\Api\Data\CreditmemoCommentCreationInterface|null $comment
     * @param bool $forceSyncMode
     *
     * @return bool
     * @since 2.1.3
     */
    public function send(
        \Magento\Sales\Api\Data\OrderInterface $order,
        \Magento\Sales\Api\Data\CreditmemoInterface $creditmemo,
        \Magento\Sales\Api\Data\CreditmemoCommentCreationInterface $comment = null,
        $forceSyncMode = false
    ) {
        $creditmemo->setSendEmail(true);

        if (!$this->globalConfig->getValue('sales_email/general/async_sending') || $forceSyncMode) {
            $transport = [
                'order' => $order,
                'creditmemo' => $creditmemo,
                'comment' => $comment ? $comment->getComment() : '',
                'billing' => $order->getBillingAddress(),
                'payment_html' => $this->getPaymentHtml($order),
                'store' => $order->getStore(),
                'formattedShippingAddress' => $this->getFormattedShippingAddress($order),
                'formattedBillingAddress' => $this->getFormattedBillingAddress($order),
            ];

            $this->eventManager->dispatch(
                'email_creditmemo_set_template_vars_before',
                ['sender' => $this, 'transport' => $transport]
            );

            $this->templateContainer->setTemplateVars($transport);

            if ($this->checkAndSend($order)) {
                $creditmemo->setEmailSent(true);

                $this->creditmemoResource->saveAttribute($creditmemo, ['send_email', 'email_sent']);

                return true;
            }
        } else {
            $creditmemo->setEmailSent(null);

            $this->creditmemoResource->saveAttribute($creditmemo, 'email_sent');
        }

        $this->creditmemoResource->saveAttribute($creditmemo, 'send_email');

        return false;
    }

    /**
     * Returns payment info block as HTML.
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     *
     * @return string
     * @since 2.1.3
     */
    private function getPaymentHtml(\Magento\Sales\Api\Data\OrderInterface $order)
    {
        return $this->paymentHelper->getInfoBlockHtml(
            $order->getPayment(),
            $this->identityContainer->getStore()->getStoreId()
        );
    }
}
