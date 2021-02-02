<?php
/**
 * Performs host registration
 *
 * PHP version 5
 *
 * @category Registration
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
/**
 * Performs host registration
 *
 * @category Registration
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class Registration extends FOGBase
{
    /**
     * The MACs to register with.
     *
     * @var array
     */
    protected $MACs = [];
    /**
     * The host's primary mac.
     *
     * @var string
     */
    protected $PriMAC;
    /**
     * The simplified mac as a name
     *
     * @var string
     */
    protected $macsimple;
    /**
     * The host modules to associate to this host.
     *
     * @var array
     */
    protected $modulesToJoin;
    /**
     * The host description if needed.
     *
     * @var string
     */
    protected $description;
    /**
     * Initialize the registration class.
     *
     * @param bool $check to check if exists.
     *
     * @return void
     */
    public function __construct($check = false)
    {
        parent::__construct();
        if (!self::getSetting('FOG_REGISTRATION_ENABLED')) {
            return;
        }
        try {
            $this->MACs = self::getHostItem(
                false,
                true,
                true,
                true
            );
            $this->regExists($check);
            $this->PriMAC = array_shift($this->MACs);
            $this->macsimple = strtolower(
                str_replace(
                    [':', '-'],
                    '',
                    $this->PriMAC
                )
            );
            $find = ['isDefault' => 1];
            Route::ids(
                'module',
                $find
            );
            $this->modulesToJoin = json_decode(
                Route::getData(),
                true
            );
            $this->description = sprintf(
                '%s %s',
                _('Created by FOG Reg on'),
                self::formatTime('now', 'F j, Y, g:i a')
            );
            if (isset($_REQUEST['advanced'])) {
                $this->_fullReg();
            } elseif (self::getSetting('FOG_QUICKREG_AUTOPOP')) {
                $this->_quickRegAuto();
            } else {
                $this->_quickReg();
            }
        } catch (Exception $e) {
            die($e->getMessage());
        }
    }
    /**
     * Checks if the host exists or not.
     *
     * @param bool $check whether to really check.
     *
     * @return bool
     */
    public function regExists($check = false)
    {
        try {
            if (self::$Host->isValid()) {
                throw new Exception(
                    sprintf(
                        '%s %s',
                        _('Already registered as'),
                        self::$Host->get('name')
                    )
                );
            }
        } catch (Exception $e) {
            echo $e->getMessage();
            return true;
        }
        if ($check === true) {
            throw new Exception('#!ok');
        }
        return false;
    }
    /**
     * Perform the registration.
     *
     * @return void
     */
    private function _fullReg()
    {
        try {
            if (self::$Host->isValid()) {
                return;
            }
            self::stripAndDecode($_REQUEST);
            $productKey = $_REQUEST['productKey'];
            $username = $_REQUEST['username'];
            $host = $_REQUEST['host'];
            $host = (
                self::getClass('Host')->isHostnameSafe($host) ?
                $host :
                $this->macsimple
            );
            $ip = $_REQUEST['ip'];
            $imageid = $_REQUEST['imageid'];
            $imageid = (
                self::getClass('Image', $imageid)->isValid() ?
                $imageid :
                0
            );
            $primaryuser = $_REQUEST['primaryuser'];
            $other1 = $_REQUEST['other1'];
            $other2 = $_REQUEST['other2'];
            $doimage = trim($_REQUEST['doimage']);
            if ($_REQUEST['doad']) {
                $serviceNames = [
                    'FOG_AD_DEFAULT_DOMAINNAME',
                    'FOG_AD_DEFAULT_OU',
                    'FOG_AD_DEFAULT_PASSWORD',
                    'FOG_AD_DEFAULT_USER',
                    'FOG_ENFORCE_HOST_CHANGES'
                ];
                list(
                    $ADDomain,
                    $OUs,
                    $ADPass,
                    $ADUser,
                    $enforce
                ) = self::getSetting($serviceNames);
                $OUs = explode(
                    '|',
                    $OUs
                );
                foreach ((array)$OUs as &$OU) {
                    $OUOptions[] = $OU;
                    unset($OU);
                }
                $OUOptions = array_unique((array)$OUOptions);
                $OUOptions = array_values((array)$OUOptions);
                if (count($OUOptions) > 1) {
                    $OUs = $OUOptions;
                    foreach ($OUs as &$OU) {
                        $opt = preg_replace('#;#', '', $OU);
                        if ($opt) {
                            break;
                        }
                        unset($OU);
                    }
                }
                if (!$opt) {
                    $opt = preg_replace('#;#', '', $OUs[0]);
                }
                $useAD = 1;
                $ADOU = $opt;
            }
            $groupsToJoin = explode(
                ',',
                $_REQUEST['groupid']
            );
            $snapinsToJoin = explode(
                ',',
                $_REQUEST['snapinid']
            );
            self::$Host = self::getClass('Host')
                ->set('name', $host)
                ->set('description', $this->description)
                ->set('imageID', $imageid)
                ->set('enforce', $enforce)
                ->set('modules', $this->modulesToJoin)
                ->addGroup($groupsToJoin)
                ->addSnapin($snapinsToJoin)
                ->addPriMAC($this->PriMAC)
                ->addMAC($this->MACs)
                ->setAD(
                    $useAD,
                    $ADDomain,
                    $ADOU,
                    $ADUser,
                    $ADPass,
                    false,
                    true,
                    $productKey
                );
            if (!self::$Host->save()) {
                throw new Exception(
                    _('Failed to create Host')
                );
            }
            self::$HookManager->processEvent(
                'HOST_REGISTER',
                ['Host' => &self::$Host]
            );
            try {
                if (!$doimage) {
                    throw new Exception(
                        _('Done, without imaging!')
                    );
                }
                if (!self::$Host->getImageMemberFromHostID()) {
                    throw new Exception(
                        _('Done, No image assigned!')
                    );
                }
                Route::indiv('tasktype', TaskType::DEPLOY);
                $tasktype = json_decode(Route::getData());
                $task = self::$Host->createImagePackage(
                    $tasktype,
                    'AutoRegTask',
                    false,
                    false,
                    true,
                    false,
                    $username
                );
                if (!$task) {
                    throw new Exception(
                        _('Done, Failed to create tasking')
                    );
                }
                throw new Exception(
                    _('Done, with imaging!')
                );
            } catch (Exception $e) {
                echo $e->getMessage();
            }
            self::getClass('Inventory')
                ->set('hostID', self::$Host->get('id'))
                ->set('primaryUser', $primaryuser)
                ->set('other1', $other1)
                ->set('other2', $other2)
                ->save();
        } catch (Exception $e) {
            echo $e->getMessage();
        }
    }
    /**
     * Quick registration handler.
     *
     * @return void
     */
    private function _quickRegAuto()
    {
        try {
            if (self::$Host->isValid()) {
                return;
            }
            $serviceNames = [
                'FOG_QUICKREG_GROUP_ASSOC',
                'FOG_QUICKREG_IMG_ID',
                'FOG_QUICKREG_IMG_WHEN_REG',
                'FOG_QUICKREG_SYS_NAME',
                'FOG_QUICKREG_SYS_NUMBER'
            ];
            list(
                $groupsToJoin,
                $imageid,
                $performimg,
                $autoRegSysName,
                $autoRegSysNumber
            ) = self::getSetting($serviceNames);
            $autoRegSysName = trim($autoRegSysName);
            if (strtoupper($autoRegSysName) == 'MAC') {
                $hostname = $this->macsimple;
            } else {
                $hostname = $autoRegSysName;
                $sysserial = filter_input(INPUT_POST, 'sysserial');
                $sysserial = base64_decode($sysserial);
                $sysserial = strtoupper($sysserial);
                $hostname = str_replace('{SYSSERIAL}', $sysserial, $hostname);
            }
            $hostname = trim($hostname);
            if (!self::getClass('Host')->isHostnameSafe($hostname)) {
                $hostname = $this->macsimple;
            }
            $paddingLen = substr_count(
                $autoRegSysName,
                '*'
            );
            $paddingString = null;
            if ($paddingLen > 0) {
                $paddingString = str_repeat(
                    '*',
                    $paddingLen
                );
                $paddedInsert = str_pad(
                    $autoRegSysNumber,
                    $paddingLen,
                    0,
                    STR_PAD_LEFT
                );
                if (strtoupper($autoRegSysName) == 'MAC') {
                    $hostname = $this->macsimple;
                } else {
                    $hostname = str_replace(
                        $paddingString,
                        $paddedInsert,
                        $autoRegSysName
                    );
                    while (self::getClass('HostManager')->exists($hostname)) {
                        $paddingString = str_repeat(
                            '*',
                            $paddingLen
                        );
                        $paddedInsert = str_pad(
                            ++$autoRegSysNumber,
                            $paddingLen,
                            0,
                            STR_PAD_LEFT
                        );
                        $hostname = str_replace(
                            $paddingString,
                            $paddedInsert,
                            $autuRegSysName
                        );
                    }
                }
            }
            if (!self::getClass('Host')->isHostnameSafe($hostname)) {
                $hostname = $this->macsimple;
            }
            self::setSetting('FOG_QUICKREG_SYS_NUMBER', ++$autoRegSysNumber);
            self::$Host = self::getClass('Host')
                ->set('name', $hostname)
                ->set('description', $this->description)
                ->set('imageID', $imageid)
                ->set('modules', $this->modulesToJoin)
                ->addGroup($groupsToJoin)
                ->addPriMAC($this->PriMAC)
                ->addMAC($this->MACs);
            if (self::getSetting('FOG_QUICKREG_PROD_KEY_BIOS') > 0) {
                $productKey = base64_decode($_REQUEST['productKey']);
                self::$Host->set('productKey', $productKey);
            }
            self::$HookManager->processEvent(
                'HOST_REGISTER',
                ['Host' => &self::$Host]
            );
            if (!self::$Host->save()) {
                throw new Exception(_('Failed to create Host'));
            }
            if ($imageid
                && $performimg
                && self::$Host->getImageMemberFromHostID()
            ) {
                Route::indiv('tasktype', self::DEPLOY);
                $tasktype = json_decode(Route::getData());
                $imageTest = self::$Host
                    ->createImagePackage(
                        $tasktype,
                        'AutoRegTask',
                        false,
                        false,
                        true,
                        false,
                        $username
                    );
                if ($imageTest) {
                    throw new Exception(
                        _('Done, Failed to create tasking')
                    );
                }
                throw new Exception(
                    _('Done, with imaging!')
                );
            }
            throw new Exception(
                _('Done')
            );
        } catch (Exception $e) {
            echo $e->getMessage();
        }
    }
    /**
     * The quick registration, non-auto
     *
     * @return void
     */
    private function _quickReg()
    {
        try {
            if (self::$Host->isValid()) {
                return;
            }
            self::$Host = self::getClass('Host')
                ->set('name', $this->macsimple)
                ->set('description', $this->description)
                ->set('modules', $this->modulesToJoin)
                ->addPriMAC($this->PriMAC)
                ->addMAC($this->MACs);
            if (self::getSetting('FOG_QUICKREG_PROD_KEY_BIOS') > 0) {
                $productKey = base64_decode($_REQUEST['productKey']);
                self::$Host->set('productKey', $productKey);
            }
            self::$HookManager->processEvent(
                'HOST_REGISTER',
                ['Host' => &self::$Host]
            );
            if (!self::$Host->save()) {
                throw new Exception(
                    _('Failed to create Host')
                );
            }
            throw new Exception(
                _('Done')
            );
        } catch (Exception $e) {
            echo $e->getMessage();
        }
    }
}
