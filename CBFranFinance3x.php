<?php
/*************************************************************************************/
/*      This file is part of the Thelia package.                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : dev@thelia.net                                                       */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE.txt  */
/*      file that was distributed with this source code.                             */
/*************************************************************************************/

namespace CBFranFinance3x;

use Atos\Atos;
use CBFranFinance3x\Exception\FileCopyException;
use CBFranFinance3x\Model\Config\CBFranFinance3xConfigValue;
use Propel\Runtime\Connection\ConnectionInterface;
use Symfony\Component\Finder\Finder;
use Thelia\Model\CountryQuery;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Order;

/**
 * Class CBFranFinance3x
 * @package CBFranFinance3x
 * @author Benjamin Perche <benjamin@thelia.net>
 */
class CBFranFinance3x extends Atos
{
    const TYPE_ALPHA = "A";
    const TYPE_ALPHANUM = "AN";
    const TYPE_NUMERIQUE = "N";
    const TYPE_ALPHANUM_SYMBOL = "ANS";

    const FALLBACK_PHONE = "0600000000";

    const MESSAGE_DOMAIN = "cbfranfinance3x";
    const ROUTER = "router.cbfranfinance3x";

    protected $addParameterCallable;

    public function postActivation(ConnectionInterface $con = null)
    {
        if (null === static::getConfigValue(CBFranFinance3xConfigValue::MINIMUM_PRICE)) {
            static::setConfigValue(CBFranFinance3xConfigValue::MINIMUM_PRICE, 100);
        }

        if (null === static::getConfigValue(CBFranFinance3xConfigValue::MAXIMUM_PRICE)) {
            static::setConfigValue(CBFranFinance3xConfigValue::MAXIMUM_PRICE, 3000);
        }

        // Deploy logo
        $files = Finder::create()->files()->in(__DIR__.DS."logo");
        $destination = $this->getAtosLogoPath();

        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            $fileDestination = $destination.$file->getFilename();

            if (!file_exists($fileDestination)) {
                if (false === @symlink($file->getRealPath(), $fileDestination)) {
                    if (false === @copy($file->getRealPath(), $fileDestination)) {
                        throw new FileCopyException(sprintf(
                            "Failed to copy file '%s' to '%s'",
                            $file->getRealPath(),
                            $fileDestination
                        ));
                    }
                }
            }
        }
    }

    /**
     *
     *  Method used by payment gateway.
     *
     *  If this method return a \Thelia\Core\HttpFoundation\Response instance, this response is send to the
     *  browser.
     *
     *  In many cases, it's necessary to send a form to the payment gateway. On your response you can return this form already
     *  completed, ready to be sent
     *
     * @param  \Thelia\Model\Order                       $order processed order
     * @return null|\Thelia\Core\HttpFoundation\Response
     */
    public function pay(Order $order)
    {
        $customer = $order->getCustomer();
        $defaultAddress = $customer->getDefaultAddress();
        $address = $order->getOrderAddressRelatedByInvoiceOrderAddressId();

        $this->addParam("payment_means", "3XCBFRANFINANCE,1");

        // Build 3XCBFRANFINANCE_DATA field
        // There's 23 fields into the data. They are separate by "#"

        $data[] = ""; // Encoding: UTF-8
        $data[] = static::getConfigValue(CBFranFinance3xConfigValue::AUTHENTICATION_KEY); // Authentication, given by FRANFINANCE | MANDATORY | size: 512
        $data[] = $this->filterString(
            static::getConfigValue(CBFranFinance3xConfigValue::CUSTOMIZE_CODE),
            static::TYPE_ALPHANUM,
            5
        ); // Customize code | size: 5

        // Get title
        $title = $customer->getCustomerTitle();
        $titleI18n = $title->getTranslation();

        // Atos title
        $atosTitle = "";

        switch ($titleI18n->getLong()) {
            case "Mister":
                $atosTitle = "MR";
                break;
            case "Misses":
                $atosTitle = "MLE";
                break;
            case "Miss":
                $atosTitle = "MME";
                break;
        }

        $data[] = $this->filterString("", static::TYPE_ALPHANUM, 64); // Code opt 1 | size: 64
        $data[] = $this->filterString("", static::TYPE_ALPHANUM, 64); // Code opt 2 | size: 64
        $data[] = $this->filterString("", static::TYPE_ALPHANUM, 64); // Code opt 3 | size: 64
        $data[] = $this->filterString(5, static::TYPE_ALPHANUM, 3); // Code opt 4, Timer for automatic redirection to the site ( 0 <=> 300 s) | size: 3
        $data[] = $this->filterString("", static::TYPE_ALPHANUM, 64); // Code opt 5 | size: 64
        $data[] = $this->filterString("", static::TYPE_NUMERIQUE, 6); // Acceptation – Pré-saisie BIN (défaut 503206) | size: 6
        $data[] = $this->filterString($atosTitle, static::TYPE_ALPHA, 3); // Customer title. MR|MME|MLE | size: 3
        $data[] = $this->filterString($customer->getLastname(), static::TYPE_ALPHA, 24); // Last name | MANDATORY | size: 24
        $data[] = $this->filterString("", static::TYPE_ALPHA, 32); // Maiden name | size: 32
        $data[] = $this->filterString($customer->getFirstname(), static::TYPE_ALPHA, 15); // First name | size: 15
        $data[] = $this->filterString("", static::TYPE_ALPHANUM_SYMBOL, 10); // Birthday | size: 10 | format: jj.mm.ssaa
        $data[] = $this->filterString("", static::TYPE_NUMERIQUE, 1); // Birth country | size: 1 | 1: France, 2: other
        $data[] = $this->filterString("", static::TYPE_NUMERIQUE, 3); // Birth state | size: 3 | example: 63 for Puy-de-dome. 999 for foreigners
        $data[] = $this->filterString("", static::TYPE_ALPHA, 26); // Birth city | size: 26
        $data[] = $this->filterString($address->getAddress1(), static::TYPE_ALPHANUM, 32); // Address | size: 32 | MANDATORY
        $data[] = $this->filterString($address->getAddress2(), static::TYPE_ALPHANUM, 32); // Address 2 | size: 32
        $data[] = $this->filterString($address->getZipcode(), static::TYPE_NUMERIQUE, 5); // Zipcode | size: 5 | MANDATORY
        $data[] = $this->filterString($address->getCity(), static::TYPE_ALPHA, 26); // City | size: 26 | MANDATORY
        $data[] = $this->filterString($address->getPhone() ?: $defaultAddress->getPhone(), static::TYPE_NUMERIQUE, 10); // Phone | size: 10       |-\ One of those two is required
        $data[] = $this->filterString($defaultAddress->getCellphone() ?: static::FALLBACK_PHONE, static::TYPE_NUMERIQUE, 10); // Cellphone | size: 10   |-/

        $data[] = ";"; // There's a mandatory semi colon at the end
        $this->addParam("data", sprintf("\"3XCBFRANFINANCE_DATA=%s\"", utf8_encode(implode("#", $data))));

        return parent::pay($order);
    }

    /**
     *
     * This method is call on Payment loop.
     *
     * If you return true, the payment method will de display
     * If you return false, the payment method will not be display
     *
     * @return boolean
     *
     * The currency MUST be euro. Said in the documentation, 3.2: la devise doit être l'Euro (=978)
     */
    public function isValidPayment()
    {
        $parent = parent::isValidPayment();

        if (!$parent) {
            return false;
        }

        $eurCurrency = CurrencyQuery::create()->findOneByCode("EUR");

        /** @var \Thelia\Core\HttpFoundation\Session\Session $session */
        $session = $this->request->getSession();
        $customer = $session->getCustomerUser();
        $country = CountryQuery::create()
            ->useAddressQuery()
                ->filterByCustomerId($customer->getId())
            ->filterByIsDefault(true)
            ->endUse()
            ->findOne()
        ;

        $cartPrice = $session->getSessionCart($this->dispatcher)->getTaxedAmount($country);
        $key = static::getConfigValue(CBFranFinance3xConfigValue::AUTHENTICATION_KEY);

        if (empty($key) ||
            null === $eurCurrency ||
            $session->getCurrency()->getId() !== $eurCurrency->getId() ||
            static::getConfigValue(CBFranFinance3xConfigValue::MINIMUM_PRICE, 100) > $cartPrice ||
            static::getConfigValue(CBFranFinance3xConfigValue::MAXIMUM_PRICE, 3000) < $cartPrice
        ) {
            return false;
        }

        return true;
    }

    protected function getAtosLogoPath()
    {
        return THELIA_MODULE_DIR."Atos".DS."logo".DS;
    }

    public static function setConfigValue($variableName, $variableValue, $valueLocale = null, $createIfNotExists = true)
    {
        parent::setConfigValue($variableName, $variableValue, "en_US", $createIfNotExists);
    }

    public static function getConfigValue($variableName, $defaultValue = null, $valueLocale = null)
    {
        return parent::getConfigValue($variableName, $defaultValue, "en_US");
    }

    protected function filterString($string, $type, $size = null)
    {
        switch ($type) {
            case static::TYPE_ALPHA:
                $string = preg_replace("/[^a-z ]/i", " ", $string);
                break;
            case static::TYPE_ALPHANUM:
                $string = preg_replace("/[^a-z\d ]/i", " ", $string);
                break;
            case static::TYPE_NUMERIQUE:
                $string = preg_replace("/[^\d]/", "", $string);
                break;
            case static::TYPE_ALPHANUM_SYMBOL:
                $string = preg_replace("/[^a-z\d_\-\. ]/i", " ", $string);
                break;
            default:
                throw new \LogicException(sprintf(
                    "The type '%s' is not supported for filter",
                    $type
                ));
        }

        if (is_int($size)) {
            $string = substr($string, 0, $size);
        }

        return $string;
    }
}
