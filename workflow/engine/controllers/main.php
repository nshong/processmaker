<?php
/**
 * Main Controller for processMaker v2.1
 * @date Jul 17, 2011
 * @author Erik Amaru Ortiz <erik@colosa.com>
 */

class Main extends Controller
{
  private $memcache;
  private $conf;

  public function __construct()
  {
    G::LoadClass('memcached');
    $this->memcache = & PMmemcached::getSingleton(defined('SYS_SYS') ? SYS_SYS : '');

    define('ERROR_EXCEPTION'  , 1);
    define('INFO_EXCEPTION'   , 3);
    define('WARNING_EXCEPTION', 2);

     //$this->setDebug(true);
  }

  function index($httpData)
  {
    global $RBAC;
    $RBAC->requirePermissions('PM_LOGIN');

    $meta = new stdClass;
    $this->includeExtJS('main/index');
    $this->includeExtJSLib('ux/ux.menu');
    $this->setView('main/index');

    // setting variables for template
    $this->setVar('logo_company', $this->getCompanyLogo());
    $this->setVar('userfullname', $this->getUserFullName());
    $this->setVar('user', isset($_SESSION['USR_USERNAME']) ? $_SESSION['USR_USERNAME'] : '');
    $this->setVar('pipe', isset($_SESSION['USR_USERNAME']) ? ' | ' : '');
    $this->setVar('rolename', $this->getUserRole());
    $this->setVar('logout', G::LoadTranslation('ID_LOGOUT'));
    $this->setVar('workspace', defined('SYS_SYS')? ucfirst(SYS_SYS): '');
    $this->setVar('user_avatar', 'users/users_ViewPhotoGrid?pUID=' . $_SESSION['USER_LOGGED'].'&h=' . rand());
    $this->setVar('udate', G::getformatedDate(date('Y-m-d'), 'M d, yyyy', SYS_LANG));

    // setting variables on javascript env.
    $this->setJSVar('meta', array('menu'=>$this->getMenu()));
    $this->setJSVar('activeTab', $this->getActiveTab());
    $this->setJSVar('urlAddGetParams', $this->getUrlGetParams());

    $showSystemInfo = $RBAC->userCanAccess('PM_SETUP') == 1;

    $this->setJSVar('showSystemInfo', $showSystemInfo);

    if (($flyNotify = $this->getFlyNotify()) !== false) {
      $this->setJSVar('flyNotify', $flyNotify);
    }

    G::RenderPage('publish', 'extJs');
  }

  function getSystemInfo()
  {
    $this->setResponseType('json');
    $infoList = $this->_getSystemInfo();
    $data = array();

    foreach ($infoList as $row) {
      $data[] = array(
        'label' => $row[0],
        'value' => $row[1],
        'section' => $row[2],
      );
    }
    return $data;
  }

  /**
   * Login
   */
  public function login()
  {
    require_once 'classes/model/LoginLog.php';
    G::LoadClass('system');
    G::loadClass('configuration');
    $this->conf = new Configurations;

    // getting posibles errors passed by GET method
    $this->getInUrlError();

    if (!isset ($_SESSION ['G_MESSAGE'])) {
      $_SESSION['G_MESSAGE'] = '';
    }
    if (!isset ($_SESSION ['G_MESSAGE_TYPE'])) {
      $_SESSION['G_MESSAGE_TYPE'] = '';
    }

    $msg = $_SESSION['G_MESSAGE'];
    $msgType = $_SESSION['G_MESSAGE_TYPE'];

    if (! isset($_SESSION['FAILED_LOGINS'])) {
      $_SESSION['FAILED_LOGINS'] = 0;
    }
    $sFailedLogins = $_SESSION ['FAILED_LOGINS'];

    if ( isset ($_SESSION ['USER_LOGGED']) ) {
    //close the session, if the current session_id was used in PM.
      $oCriteria = new Criteria ( 'workflow' );
      $oCriteria->add ( LoginLogPeer::LOG_SID, session_id () );
      $oCriteria->add ( LoginLogPeer::USR_UID, isset ( $_SESSION ['USER_LOGGED'] ) ? $_SESSION ['USER_LOGGED'] : '-' );
      $oCriteria->add ( LoginLogPeer::LOG_STATUS, 'ACTIVE' );
      $oCriteria->add ( LoginLogPeer::LOG_END_DATE, NULL, Criteria::ISNULL );
      $oDataset = LoginLogPeer::doSelectRS ( $oCriteria );
      $oDataset->setFetchmode ( ResultSet::FETCHMODE_ASSOC );
      $oDataset->next ();
      $aRow = $oDataset->getRow ();
      if ($aRow) {
        if ($aRow ['LOG_STATUS'] != 'CLOSED' && $aRow ['LOG_END_DATE'] == NULL) {
          $weblog = new LoginLog ( );
          $aLog ['LOG_UID'] = $aRow ['LOG_UID'];
          $aLog ['LOG_STATUS'] = 'CLOSED';
          $aLog ['LOG_IP'] = $aRow ['LOG_IP'];
          $aLog ['LOG_SID'] = session_id ();
          $aLog ['LOG_INIT_DATE'] = $aRow ['LOG_INIT_DATE'];
          $aLog ['LOG_END_DATE'] = date ( 'Y-m-d H:i:s' );
          $aLog ['LOG_CLIENT_HOSTNAME'] = $aRow ['LOG_CLIENT_HOSTNAME'];
          $aLog ['USR_UID'] = $aRow ['USR_UID'];
          $weblog->update ( $aLog );
        }
      }
      //remove memcached session
      $this->memcache->delete( 'rbacSession' . session_id() ) ;
    }
    else {
      // Execute SSO trigger
      $pluginRegistry =& PMPluginRegistry::getSingleton();
      if (defined('PM_SINGLE_SIGN_ON')) {
        if ($pluginRegistry->existsTrigger(PM_SINGLE_SIGN_ON)) {
          if ($pluginRegistry->executeTriggers(PM_SINGLE_SIGN_ON, null)) {
            // Start new session
            @session_destroy();
            session_start();
            session_regenerate_id();
            // Authenticate
            $result = $this->authentication();
            if ($result->success) {
              // Redirect to landing page for the user
              G::header('Location: ' . $result->url);
              die();
            }
          }
        }
      }
    }
    //end log

    //start new session
    @session_destroy ();
    session_start ();
    session_regenerate_id ();

    if (strlen ( $msg ) > 0) {
      $_SESSION ['G_MESSAGE'] = $msg;
    }
    if (strlen ( $msgType ) > 0) {
      $_SESSION ['G_MESSAGE_TYPE'] = $msgType;
    }
    $_SESSION ['FAILED_LOGINS'] = $sFailedLogins;

    $availableLangArray = $this->getLanguagesList();

    //$G_PUBLISH = new Publisher ( );
    //$G_PUBLISH->AddContent ( 'xmlform', 'xmlform', 'login/login', '', $aFields, SYS_URI . 'login/authentication.php' );

    G::LoadClass ( 'serverConfiguration' );

    if ( ($nextBeatDate = $this->memcache->get('nextBeatDate')) === false ) {
      //get the serverconf singleton, and check if we can send the heartbeat
      $oServerConf = & serverConf::getSingleton ();

      $sflag = $oServerConf->getHeartbeatProperty('HB_OPTION','HEART_BEAT_CONF');
      $sflag = (trim($sflag)!='')?$sflag:'1';

      //get date of next beat
      $nextBeatDate = $oServerConf->getHeartbeatProperty('HB_NEXT_BEAT_DATE','HEART_BEAT_CONF');
      $this->memcache->set( 'nextBeatDate', $nextBeatDate, 1*3600 );
    }

    $sflag = 1;
    //TODO analize this & enable to work with this new login
    // if flag to send heartbeat is enabled, and it is time to send heartbeat, sent it using asynchronous beat.
    // if( ($sflag=="1") && ((strtotime ( "now" ) > $nextBeatDate) || is_null($nextBeatDate) ) ){
    //   $oHeadPublisher =& headPublisher::getSingleton();
    //   //To do: we need to change to ExtJs
    //   $oHeadPublisher->addScriptCode( 'var flagHeartBeat = 1; ');
    // }
    // else
    //   $oHeadPublisher->addScriptCode( 'var flagHeartBeat = 0; ');

    //check if we show the panel with the getting started info
    if ( ($flagGettingStarted = $this->memcache->get('flagGettingStarted')) === false ) {
      require_once 'classes/model/Configuration.php';
      $oConfiguration = new Configuration ( );
      $oCriteria = new Criteria ( 'workflow' );
      $oCriteria->add ( ConfigurationPeer::CFG_UID, 'getStarted' );
      $oCriteria->add ( ConfigurationPeer::OBJ_UID, '' );
      $oCriteria->add ( ConfigurationPeer::CFG_VALUE, '1' );
      $oCriteria->add ( ConfigurationPeer::PRO_UID, '' );
      $oCriteria->add ( ConfigurationPeer::USR_UID, '' );
      $oCriteria->add ( ConfigurationPeer::APP_UID, '' );
      $flagGettingStarted =  ConfigurationPeer::doCount ( $oCriteria );
      $this->memcache->set( 'flagGettingStarted', $flagGettingStarted, 8*3600 ) ;
    }

    // if( $flagGettingStarted == 0 ) {
    //   $oHeadPublisher->addScriptCode( 'var flagGettingStarted = 1; ');
    // }
    // else
    //   $oHeadPublisher->addScriptCode( 'var flagGettingStarted = 0; ');

    G::loadClass('configuration');
    $oConf = new Configurations;
    $oConf->loadConfig($obj, 'ENVIRONMENT_SETTINGS','');

    $flagForgotPassword = isset($oConf->aConfig['login_enableForgotPassword'])? $oConf->aConfig['login_enableForgotPassword']: 'off';
    //$oHeadPublisher->addScriptCode("var flagForgotPassword = '$flagForgotPassword';");

    $this->includeExtJSLib('ux/virtualkeyboard');
    $this->includeExtJS('main/login');
    $this->setView('main/login');

    $oConf->loadConfig($obj, 'ENVIRONMENT_SETTINGS','');

    $forgotPasswd    = isset($oConf->aConfig['login_enableForgotPassword'])? $oConf->aConfig['login_enableForgotPassword']: false;
    $virtualKeyboad  = isset($oConf->aConfig['login_enableVirtualKeyboard'])? $oConf->aConfig['login_enableVirtualKeyboard']: false;
    $defaultLanguaje = isset($oConf->aConfig['login_defaultLanguage'])? $oConf->aConfig['login_defaultLanguage']: 'en';


    $this->setJSVar('forgotPasswd', $forgotPasswd);
    $this->setJSVar('virtualKeyboad', $virtualKeyboad);

    $this->setJSVar('languages', $availableLangArray);
    $this->setJSVar('defaultLang', $defaultLanguaje);

    //binding G::SendTemporalMessage() to Ext.msgBoxSlider.msgTopCenter()
    if (($flyNotify = $this->getFlyNotify()) !== false) {
      $this->setJSVar('flyNotify', $flyNotify);
    }

    //binding G::SendTemporalMessage() to Ext.msgBoxSlider.msgTopCenter()
    if (isset($_GET['u'])) {
      $this->setJSVar('urlRequested', urldecode($_GET['u']));
    }

    $this->setVar('logo_company', $this->getCompanyLogo());
    $this->setVar('pmos_version', System::getVersion());

    $footerText = 'Copyright © 2003-2011 Colosa, Inc. All rights reserved.';
    $adviseText = 'Supplied free of charge with no support, certification, warranty,
               maintenance nor indemnity by Colosa and its Certified Partners. ';
    $this->setVar('footer_text', $footerText);
    $this->setVar('advise_text', $adviseText);
    $loginScript  = $this->getHeadPublisher()->getExtJsLibraries();
    $loginScript .= $this->getHeadPublisher()->getExtJsScripts();
    $this->setVar('login_script', $loginScript);
    $this->setVar('login_vars', $this->getHeadPublisher()->getExtJsVariablesScript());

    G::RenderPage('publish', 'plain');
  }

  /**
   * SysLogin
   */
  public function sysLogin()
  {
    require_once ("propel/Propel.php");
    require_once ("creole/Creole.php");
    G::LoadClass('system');
    G::LoadThirdParty ( "pake", "pakeColor.class" );
    Propel::init ( PATH_CORE . "config/databases.php" );
    Creole::registerDriver ( 'dbarray', 'creole.contrib.DBArrayConnection' );

    // getting posibles errors passed by GET method
    $this->getInUrlError();

    $availableWorkspace = $this->getWorkspacesAvailable();
    $availableWorkspaceList = array();

    foreach ($availableWorkspace as $ws) {
      $availableWorkspaceList[] = array($ws, $ws);
    }

    $aField ['LOGIN_VERIFY_MSG'] = G::loadTranslation('LOGIN_VERIFY_MSG');

    //Get Server Configuration
    G::LoadClass ( 'serverConfiguration' );
    $oServerConf = & serverConf::getSingleton ();

    $availableLangArray = $this->getLanguagesList();

    $this->includeExtJSLib('ux/virtualkeyboard');
    $this->includeExtJS('main/sysLogin');
    $this->setView('main/sysLogin');

    $this->setVar('logo_company', $this->getCompanyLogo());
    $this->setVar('pmos_version', System::getVersion());

    $footerText = 'Copyright © 2003-2011 Colosa, Inc. All rights reserved.';
    $adviseText = 'Supplied free of charge with no support, certification, warranty,
               maintenance nor indemnity by Colosa and its Certified Partners. ';
    $this->setVar('footer_text', $footerText);
    $this->setVar('advise_text', $adviseText);

    //binding G::SendTemporalMessage() to Ext.msgBoxSlider.msgTopCenter()
    if (($flyNotify = $this->getFlyNotify()) !== false) {
      $this->setJSVar('flyNotify', $flyNotify);
    }

    $this->setJSVar('languages', $availableLangArray);
    $this->setJSVar('workspaces', $availableWorkspaceList);
    $this->setJSVar('wsPrivate', $oServerConf->getProperty('LOGIN_NO_WS'));

    $this->setJSVar('defaultLang', 'en');
    $this->setJSVar('defaultWS', '');

    $loginScript  = $this->getHeadPublisher()->getExtJsLibraries();
    $loginScript .= $this->getHeadPublisher()->getExtJsScripts();
    $this->setVar('login_script', $loginScript);
    $this->setVar('login_vars', $this->getHeadPublisher()->getExtJsVariablesScript());

    G::RenderPage('publish', 'plain');

  }

  public function sysLoginVerify()
  {
    $this->setResponseType('json');

    try {
      if (!defined('SYS_SYS')) {
        if (!isset($_POST)) {
          throw new Exception('Bad Request!', ERROR_EXCEPTION);
        }

        if (!isset($_POST['workspace'])) {
          throw new Exception('No workspace specified!', ERROR_EXCEPTION);
        }

        session_start();
        $_SESSION['sysLogin'] = $_POST;

        $url = '/sys' . $_POST['workspace'] . '/' . SYS_LANG . '/' . SYS_SKIN . '/main/sysLoginVerify';

        $result->success = true;
        $result->url = $url;
        $result->message = G::LoadTranslation('LOGIN_VERIFY_MSG');
      } else {
        $_POST = $_SESSION['sysLogin'];
        $result = $this->authentication();

        if ($result->success) {
          G::header ( 'location: ' . $result->url);
        } else {
          G::SendTemporalMessage($result->message, "error", 'string');
          G::header('location: /sys' . $_POST['workspace'] . '/' . SYS_LANG . '/' . SYS_SKIN . '/main/login');
        }
      }
    } catch ( Exception $e ) {
      $result->success = false;
      $result->message = $e->getMessage();
      $result->errorCode = $e->getCode();
    }

    return $result;
  }

  public function authentication()
  {
    global $RBAC;

    $this->setResponseType('json');

    try {
      if (!$RBAC->singleSignOn) {
        if (!isset($_POST['user']) ) {
          throw new Exception(G::LoadTranslation('ID_USER_HAVENT_RIGHTS_SYSTEM'));
        }

        $frm = $_POST;
        $usr = '';
        $pwd = '';
        $lang = '';

        if (isset($frm['user'])) {
          $usr = strtolower(trim($frm['user']));
          $pwd = $currentPwd = trim($frm['password']);
          $lang = trim($frm['lang']) != '' ? $frm['lang'] : SYS_LANG;
        }

        $uid = $RBAC->VerifyLogin($usr , $pwd);

        switch ($uid) {
          //The user doesn't exist
          case -1:
            throw new Exception(G::LoadTranslation('ID_USER_NOT_REGISTERED'));
            break;
          //The password is incorrect
          case -2:
            throw new Exception(G::LoadTranslation('ID_WRONG_PASS'));

            if(isset($_SESSION['__AUTH_ERROR__'])){
              G::SendMessageText($_SESSION['__AUTH_ERROR__'], "warning");
              unset($_SESSION['__AUTH_ERROR__']);
            }
            break;
          //The user is inactive
          case -3:
            require_once 'classes/model/Users.php';
            $user = new Users;
            $aUser = $user->loadByUsernameInArray($usr);
            switch($aUser['USR_STATUS']){
              case 'VACATION':
                throw new Exception(G::LoadTranslation('ID_USER_ONVACATION', WARNING_EXCEPTION));
                break;
              case 'INACTIVE':
                throw new Exception(G::LoadTranslation('ID_USER_INACTIVE', WARNING_EXCEPTION));
                break;
            }
            break;
          //The Due date is finished
          case -4:
            throw new Exception(G::LoadTranslation('ID_USER_INACTIVE_BY_DATE', WARNING_EXCEPTION));
            break;
          case -5:
            throw new Exception(G::LoadTranslation('ID_AUTHENTICATION_SOURCE_INVALID', WARNING_EXCEPTION));
            break;
        }

        //to avoid empty string in user field.  This will avoid a weird message "this row doesn't exist"
        if ( !isset($uid) ) {
          $uid = -1;
          throw new Exception(G::LoadTranslation('ID_USER_NOT_REGISTERED', WARNING_EXCEPTION));
        }

        if ( !isset($uid) || $uid < 0 ) {
          if(isset($_SESSION['FAILED_LOGINS']))
            $_SESSION['FAILED_LOGINS']++;
          if (!defined('PPP_FAILED_LOGINS')) {
            define('PPP_FAILED_LOGINS', 0);
          }
          if (PPP_FAILED_LOGINS > 0) {
            if ($_SESSION['FAILED_LOGINS'] >= PPP_FAILED_LOGINS) {
              $oConnection = Propel::getConnection('rbac');
              $oStatement  = $oConnection->prepareStatement("SELECT USR_UID FROM USERS WHERE USR_USERNAME = '" . $usr . "'");
              $oDataset    = $oStatement->executeQuery();
              if ($oDataset->next()) {
                $sUserUID = $oDataset->getString('USR_UID');
                $oConnection = Propel::getConnection('rbac');
                $oStatement  = $oConnection->prepareStatement("UPDATE USERS SET USR_STATUS = 0 WHERE USR_UID = '" . $sUserUID . "'");
                $oStatement->executeQuery();
                $oConnection = Propel::getConnection('workflow');
                $oStatement  = $oConnection->prepareStatement("UPDATE USERS SET USR_STATUS = 'INACTIVE' WHERE USR_UID = '" . $sUserUID . "'");
                $oStatement->executeQuery();
                unset($_SESSION['FAILED_LOGINS']);
                G::SendMessageText(G::LoadTranslation('ID_ACCOUNT') . ' "' . $usr . '" ' . G::LoadTranslation('ID_ACCOUNT_DISABLED_CONTACT_ADMIN'), 'warning');
              }
              else {
                //Nothing
              }
            }
          }

          //TODO complete this redirection
          G::header  ("location: login.html");
          die;
        }

        //Execute the SSO Script from plugin
        $oPluginRegistry =& PMPluginRegistry::getSingleton();
        if ( $oPluginRegistry->existsTrigger ( PM_LOGIN ) ) {
          $lSession="";
          $loginInfo = new loginInfo ($usr, $pwd, $lSession  );
          $oPluginRegistry->executeTriggers ( PM_LOGIN , $loginInfo );
        }

        $_SESSION['USER_LOGGED']  = $uid;
        $_SESSION['USR_USERNAME'] = $usr;
      }
      else {
        $uid = $RBAC->userObj->fields['USR_UID'];
        $usr = $RBAC->userObj->fields['USR_USERNAME'];
        $_SESSION['USER_LOGGED']  = $uid;
        $_SESSION['USR_USERNAME'] = $usr;
      }

      if (!isset($_SESSION['WORKSPACE'])) {
        $_SESSION['WORKSPACE'] = SYS_SYS;
      }

      // Assign the uid of user to userloggedobj
      $RBAC->loadUserRolePermission($RBAC->sSystem, $uid);
      $res = $RBAC->userCanAccess('PM_LOGIN');

      if ($res != 1 ) {
        if ($res == -2)
          throw new Exception(G::LoadTranslation('ID_USER_HAVENT_RIGHTS_SYSTEM', ERROR_EXCEPTION));
        else
          throw new Exception(G::LoadTranslation('ID_USER_HAVENT_RIGHTS_PAGE', ERROR_EXCEPTION));
        die;
      }

      //using optimization with memcache, the user data will be in memcache 8 hours
      $memKey = 'rbacSession' . session_id();
      $this->memcache->set( $memKey, $RBAC->aUserInfo, PMmemcached::EIGHT_HOURS );
      
      $_SESSION['USR_FULLNAME'] = $RBAC->aUserInfo['USER_INFO']['USR_FIRSTNAME'] . ' ' . $RBAC->aUserInfo['USER_INFO']['USR_LASTNAME'];
      unset($_SESSION['FAILED_LOGINS']);

      //get the default language, language posted in form has preference
      if ( isset($frm['USER_LANG']) && $frm['USER_LANG'] != '' ) {
        $lang = $frm['USER_LANG'];
      }
      else {
        $lang = (defined('SYS_LANG')) ? SYS_LANG : 'en';
      }

      /**log in table Login**/
      require_once 'classes/model/LoginLog.php';
      $weblog=new LoginLog();
      $aLog['LOG_UID']             = G::generateUniqueID();
      $aLog['LOG_STATUS']          = 'ACTIVE';
      $aLog['LOG_IP']              = $_SERVER['REMOTE_ADDR'];
      $aLog['LOG_SID']             = session_id();
      $aLog['LOG_INIT_DATE']       = date('Y-m-d H:i:s');
      $aLog['LOG_CLIENT_HOSTNAME'] = $_SERVER['HTTP_HOST'];
      $aLog['USR_UID']             = $_SESSION['USER_LOGGED'];
      $aLog['LOG_TYPE']            = (isset($RBAC->aUserInfo['USER_INFO']['USR_AUTH_TYPE']) ? ($RBAC->aUserInfo['USER_INFO']['USR_AUTH_TYPE'] != 'MYSQL' ? $RBAC->aUserInfo['USER_INFO']['USR_AUTH_TYPE'] : 'processmaker') : 'processmaker');
      $aLog['LOG_SSO']             = (int) $RBAC->singleSignOn;
      $weblog->create($aLog);
      /**end log**/

      // increment logins in heartbeat
      G::LoadClass('serverConfiguration');
      $oServerConf =& serverConf::getSingleton();
      $oServerConf->sucessfulLogin();

    // disabling this slow process, until revisit this issue
    // //************** background processes, here we are putting some back office routines **********
    //   $oServerConf->setWsInfo(SYS_SYS,$oServerConf->getWorkspaceInfo(SYS_SYS) );
    //
    //
    // //**** defining and saving server info, this file has the values of the global array $_SERVER ****
    // //this file is useful for command line environment (no Browser), I mean for triggers, crons and other executed over command line
    //   $_CSERVER = $_SERVER;
    //   unset($_CSERVER['REQUEST_TIME']);
    //   unset($_CSERVER['REMOTE_PORT']);
    //   $cput = serialize($_CSERVER);
    //   if( !is_file(PATH_DATA_SITE . PATH_SEP . '.server_info') ){
    //     file_put_contents(PATH_DATA_SITE . PATH_SEP . '.server_info', $cput);
    //   }
    //   else {
    //     $c = file_get_contents(PATH_DATA_SITE . PATH_SEP . '.server_info');
    //     if(md5($c) != md5($cput)){
    //       file_put_contents(PATH_DATA_SITE . PATH_SEP . '.server_info', $cput);
    //        }
    //   }
    //


      /* Check password using policy - Start */
      require_once 'classes/model/UsersProperties.php';
      $oUserProperty = new UsersProperties();
      if (!$RBAC->singleSignOn) {
        $aUserProperty = $oUserProperty->loadOrCreateIfNotExists($_SESSION['USER_LOGGED'], array('USR_PASSWORD_HISTORY' => serialize(array(md5($currentPwd)))));
        $aErrors       = $oUserProperty->validatePassword($pwd, $aUserProperty['USR_LAST_UPDATE_DATE'], $aUserProperty['USR_LOGGED_NEXT_TIME']);

        if (!empty($aErrors)) {
           if (!defined('NO_DISPLAY_USERNAME')) {
            define('NO_DISPLAY_USERNAME', 1);
          }
          $aFields = array();
          $aFields['DESCRIPTION']  = '<span style="font-weight:normal;">';
          $aFields['DESCRIPTION'] .= G::LoadTranslation('ID_POLICY_ALERT').':<br /><br />';
          foreach ($aErrors as $sError)  {
            switch ($sError) {
              case 'ID_PPP_MINIMUM_LENGTH':
                $aFields['DESCRIPTION'] .= ' - ' . G::LoadTranslation($sError).': ' . PPP_MINIMUM_LENGTH . '<br />';
                $aFields[substr($sError, 3)] = PPP_MINIMUM_LENGTH;
              break;
              case 'ID_PPP_MAXIMUM_LENGTH':
                $aFields['DESCRIPTION'] .= ' - ' . G::LoadTranslation($sError).': ' . PPP_MAXIMUM_LENGTH . '<br />';
                $aFields[substr($sError, 3)] = PPP_MAXIMUM_LENGTH;
              break;
              case 'ID_PPP_EXPIRATION_IN':
                $aFields['DESCRIPTION'] .= ' - ' . G::LoadTranslation($sError).' ' . PPP_EXPIRATION_IN . ' ' . G::LoadTranslation('ID_DAYS') . '<br />';
                $aFields[substr($sError, 3)] = PPP_EXPIRATION_IN;
              break;
              default:
                $aFields['DESCRIPTION'] .= ' - ' . G::LoadTranslation($sError).'<br />';
                $aFields[substr($sError, 3)] = 1;
              break;
            }
          }

          //TODO complete this policy section (need update)
          $aFields['DESCRIPTION'] .= '<br />' . G::LoadTranslation('ID_PLEASE_CHANGE_PASSWORD_POLICY') . '<br /><br /></span>';
          //TODO need migrate to ExtJS
          // $G_PUBLISH = new Publisher;
          // $G_PUBLISH->AddContent('xmlform', 'xmlform', 'login/changePassword', '', $aFields, 'changePassword');
          // G::RenderPage('publish');
          //die;
        }
      }

      $sLocation = $oUserProperty->redirectTo($_SESSION['USER_LOGGED'], $lang);

      $result->success = true;
      $result->message = 'Logged Successfully';

      $result->url = $sLocation;
    }
    catch ( Exception $e ) {
      $result->success = false;
      $result->message = $e->getMessage();
      $result->errorCode = $e->getCode();
    }

    return $result;
  }



  public function forgotPassword($httpData)
  {
    $this->setResponseType('json');
    global $RBAC;
    require_once PATH_RBAC."model/RbacUsers.php";
    require_once 'classes/model/Users.php';
    G::LoadClass("system");

    $rbacUser = new RbacUsers();
    $user = new Users();

    try {
      $userData = $rbacUser->getByUsername($httpData->username);

      if (!$userData) {
        $msg = G::LoadTranslation('ID_USER') . ' <b>' . $httpData->username . '</b> '. G::LoadTranslation('ID_IS_NOT_REGISTERED');
        throw new Exception($msg);
      }

      if (trim($userData['USR_EMAIL']) != trim($httpData->email)) {
        $msg = G::LoadTranslation('ID_EMAIL_DOES_NOT_MATCH_FOR_USER') .' <b>'. $httpData->username . '</b>';
        throw new Exception($msg);
      }

      $newPass = G::generate_password();

      $aData['USR_UID']      = $userData['USR_UID'];
      $aData['USR_PASSWORD'] = md5($newPass);

      $rbacUser->update($aData);
      $user->update($aData);

      $subject = G::loadTranslation('ID_PROCESSMAKER_FORGOT_PASSWORD_SERVICE');

      $template = new TemplatePower(PATH_TPL . 'main/forgotPassword.tpl');
      $template->prepare();
      $template->assign('server', $_SERVER['SERVER_NAME']);

      $template->assign('serviceMsg', G::loadTranslation('ID_PROCESSMAKER_FORGOT_PASSWORD_SERVICE'));
      $template->assign('content', G::loadTranslation('ID_PASSWORD_CHANGED_SUCCESSFULLY'));
      $template->assign('passwd', $newPass);
      $template->assign('poweredBy', G::loadTranslation('ID_PROCESSMAKER_SLOGAN1'));
      $template->assign('versionLabel', G::loadTranslation('ID_VERSION'));
      $template->assign('version', System::getVersion());
      $template->assign('visit', G::loadTranslation('ID_VISIT'));

      $template->assign('footer', '');
      $body = $template->getOutputContent();

      G::sendMail('', 'ProcessMaker Service', $httpData->email, $subject, $body);

      $result->success = true;
      $result->message = G::LoadTranslation('ID_NEW_PASSWORD_SENT');
    } catch(Exception $e) {
      $result->success = false;
      $result->message = $e->getMessage();
    }
    return $result;
  }

  /**                         *
   *    Private Functions     *
   *                         **/

  private function getMenu()
  {
    global $G_MAIN_MENU;
    global $G_SUB_MENU;
    global $G_MENU_SELECTED;
    global $G_SUB_MENU_SELECTED;
    global $G_ID_MENU_SELECTED;
    global $G_ID_SUB_MENU_SELECTED;

    $G_MAIN_MENU         = 'processmaker';
    $G_SUB_MENU          = 'process';
    $G_ID_MENU_SELECTED  = 'BPMN';

    $oMenu = new Menu();
    $menus = $oMenu->generateArrayForTemplate($G_MAIN_MENU, 'SelectedMenu', 'mainMenu', $G_MENU_SELECTED, $G_ID_MENU_SELECTED);

    foreach ($menus as $i => $menu) {
      if (strpos($menu['target'], 'cases/main') !== false)
        $menus[$i]['target'] = str_replace('cases/main', 'cases/main_init', $menus[$i]['target']);

      if (strpos($menu['target'], 'bpmn/main') !== false)
        $menus[$i]['target'] = str_replace('bpmn/main', 'bpmn/mainInit', $menus[$i]['target']);

      if (strpos($menu['target'], 'processes/main') !== false)
        $menus[$i]['target'] = str_replace('processes/main', 'processes/mainInit', $menus[$i]['target']);

      if (strpos($menu['target'], 'setup/main') !== false) {
        $menus[$i]['target'] = str_replace('setup/main', 'setup/main_init', $menus[$i]['target']);

        // $param = '';
        // if (isset($_GET['i18']) || isset($_GET['i18n'])) {
        //   $s = strpos($menus[$i]['target'], '?') !== false ? '&' : '?';
        //   $menus[$i]['target'] .= $s . 'i18';
        // }
      }

      if (strpos($menu['target'], 'dashboard/main') !== false)
        $menus[$i]['target'] = str_replace('dashboard/main', 'dashboard', $menus[$i]['target']);

      $menus[$i]['elementclass'] = preg_replace(array('/class=/', '/"/'), array('', ''), $menus[$i]['elementclass']) ;
    }
    return $menus;
  }

  private function getCompanyLogo()
  {
    $sCompanyLogo = '/images/processmaker2.logo.png';
    //$sCompanyLogo = '/images/logo_processmaker2.gif';

    if(defined("SYS_SYS")){
      if ( ($aFotoSelect = $this->memcache->get('aFotoSelect')) === false ) {
        G::LoadClass( 'replacementLogo' );
        $oLogoR = new replacementLogo();
        $aFotoSelect   = $oLogoR->getNameLogo((isset($_SESSION['USER_LOGGED']))?$_SESSION['USER_LOGGED']:'');
        $this->memcache->set( 'aFotoSelect', $aFotoSelect, 1*3600 );
      }

      if ( is_array ( $aFotoSelect ) ) {
        $sFotoSelect   = trim($aFotoSelect['DEFAULT_LOGO_NAME']);
        $sWspaceSelect = trim($aFotoSelect['WORKSPACE_LOGO_NAME']);
      }
    }

    if (class_exists('PMPluginRegistry')) {
      $oPluginRegistry = &PMPluginRegistry::getSingleton();

      if ( isset($sFotoSelect) && $sFotoSelect!='' && !(strcmp($sWspaceSelect,SYS_SYS)) ){
        $sCompanyLogo = $oPluginRegistry->getCompanyLogo($sFotoSelect);
        $sCompanyLogo = "/sys".SYS_SYS."/".SYS_LANG."/".SYS_SKIN."/adminProxy/showLogoFile?id=".base64_encode($sCompanyLogo);
      }
      else {
        $sCompanyLogo = $oPluginRegistry->getCompanyLogo($sCompanyLogo);
      }
    }

    return $sCompanyLogo;
  }

  public function getLanguagesList()
  {
    //translation
    $Translations = G::getModel("Translation");
    $translationsTable = $Translations->getTranslationEnvironments();

    if ( ($languagesList = $this->memcache->get('languagesList')) === false ) {
      $languagesList = array ();

      foreach ( $translationsTable as $locale ) {
        $LANG_ID = $locale['LOCALE'];

        if( $locale['COUNTRY'] != '.' )
          $LANG_NAME = $locale['LANGUAGE'] . ' (' . (ucwords(strtolower($locale['COUNTRY']))) . ')';
        else
          $LANG_NAME = $locale['LANGUAGE'];

        $languagesList [] = array($LANG_ID, $LANG_NAME);
      }
      $this->memcache->set('languagesList', $languagesList, 1*3600 );
    }

    return $languagesList;
  }

  private function getWorkspacesAvailable() {
    G::LoadClass ( 'serverConfiguration' );
    $oServerConf = & serverConf::getSingleton ();
    $dir = PATH_DB;
    $filesArray = array ();
    if (file_exists ( $dir )) {
      if ($handle = opendir ( $dir )) {
        while ( false !== ($file = readdir ( $handle )) ) {
          if (($file != ".") && ($file != "..")) {
            if (file_exists ( PATH_DB . $file . '/db.php' )) {
              if (! $oServerConf->isWSDisabled ( $file ))
                $filesArray [] = $file;
            }
          }
        }
        closedir ( $handle );
      }
    }
    sort ( $filesArray, SORT_STRING );
    return $filesArray;
  }

  private function getUserRole()
  {
    global $RBAC;
    $rolCode = str_replace('_', ' ', $RBAC->aUserInfo['PROCESSMAKER']['ROLE']['ROL_CODE']);
    $rolUid  = $RBAC->aUserInfo['PROCESSMAKER']['ROLE']['ROL_UID'];

    $oCriteria1 = new Criteria('workflow');
    $oCriteria1->add(ContentPeer::CON_CATEGORY, 'ROL_NAME');
    $oCriteria1->add(ContentPeer::CON_ID, $rolUid);
    $oCriteria1->add(ContentPeer::CON_LANG, SYS_LANG);
    $oDataset1 = ContentPeer::doSelectRS($oCriteria1);
    $oDataset1->setFetchmode(ResultSet::FETCHMODE_ASSOC);
    $oDataset1->next();
    $aRow = $oDataset1->getRow();
    $rolName = $aRow['CON_VALUE'];

    return $rolName ? $rolName : $rolCode;
  }


  /**
   * binding G::SendTemporalMessage() to Javascript routine Ext.msgBoxSlider.msgTopCenter()
   */
  private function getFlyNotify()
  {
    if (!isset($_SESSION['G_MESSAGE'])) {
      return false;
    }

    $flyNotify['title'] = isset($_SESSION['G_MESSAGE_TITLE']) ? $_SESSION['G_MESSAGE_TITLE'] : '';
    $flyNotify['text'] = $_SESSION['G_MESSAGE'];

    unset($_SESSION['G_MESSAGE']);
    if (isset($_SESSION['G_MESSAGE_TYPE'])) {
      $flyNotify['type'] = $_SESSION['G_MESSAGE_TYPE'];
      unset($_SESSION['G_MESSAGE_TYPE']);
    }
    else {
      $flyNotify['type'] = '';
    }

    if ($flyNotify['title'] == '') {
      switch($flyNotify['type']) {
        case 'alert': case 'warning': case 'tmp-warning':
          $flyNotify['title'] = G::loadTranslation('ID_WARNING');
          break;
        case 'error': case 'tmp-error':
          $flyNotify['title'] = G::loadTranslation('ID_ERROR');
          break;
        case 'tmp-info': case 'info':
          $flyNotify['title'] = G::loadTranslation('ID_INFO');
          break;
        case 'success': case 'ok':
          $flyNotify['title'] = G::loadTranslation('ID_SUCCESS');
          break;
      }
      $flyNotify['title'] = strtoupper($flyNotify['title']);
    }

    $flyNotify['time'] = 5; //TODO make dinamic
    $this->flyNotify = $flyNotify;

    return $this->flyNotify;
  }

  private function setFlyNotify($type, $title, $text, $time = 5)
  {
    $this->flyNotify = array(
      'type'  => $type,
      'title' => $title,
      'text'  => $text,
      'time'  => $time
    );

    $_SESSION ['G_MESSAGE'] = $text;
    $_SESSION ['G_MESSAGE_TYPE'] = $type;
  }

  private function getInUrlError()
  {
    if (isset($_GET['errno'])) {
      switch ($_GET['errno']) {
        case '1': $trnLabel = 'ID_USER_HAVENT_RIGHTS_PAGE'; break;
        case '2': $trnLabel = 'ID_NOT_WORKSPACE';           break;
        default : $trnLabel = 'ID_USER_HAVENT_RIGHTS_PAGE';
      }

      $this->setFlyNotify('error', 'ERROR', G::loadTranslation($trnLabel));
    }
  }

  private function getActiveTab()
  {
    $activeTab = '';
    if (isset($_GET['st'])) {
      $activeTab = $_GET['st'];
      unset($_GET['st']);
      if (!is_numeric($activeTab)) {
        switch ($activeTab) {
          case 'home'    : $activeTab = 0; break;
          case 'bpmn'    : $activeTab = 1; break;
          case 'designer': $activeTab = 2; break;
          case 'admin'   : $activeTab = 3; break;
          default: $activeTab = 0;
        }
      }
      else {
        $activeTab = $activeTab > -1 && $activeTab < 3 ? (int) $activeTab : '';
      }
    }

    return $activeTab;
  }

  private function getUrlGetParams()
  {
      $urlGetParams = '';
      foreach ($_GET as $key => $value) {
        $urlGetParams .= $urlGetParams == '' ? $key : "&" . $key;
        $urlGetParams .= trim($value) != '' ? '=' . $value : '';
      }
    return $urlGetParams;
  }

  private function getUserFullName()
  {
    return isset($_SESSION['USR_FULLNAME']) ? $_SESSION['USR_FULLNAME'] : '';
  }

  private function _getSystemInfo()
  {
    G::LoadClass("system");

    if (getenv ( 'HTTP_CLIENT_IP' )) {
      $ip = getenv ( 'HTTP_CLIENT_IP' );
    }
    else {
      if (getenv ( 'HTTP_X_FORWARDED_FOR' )) {
        $ip = getenv ( 'HTTP_X_FORWARDED_FOR' );
      }
      else {
        $ip = getenv ( 'REMOTE_ADDR' );
      }
    }

    $redhat = '';
    if (file_exists ( '/etc/redhat-release' )) {
      $fnewsize = filesize ( '/etc/redhat-release' );
      $fp = fopen ( '/etc/redhat-release', 'r' );
      $redhat = trim ( fread ( $fp, $fnewsize ) );
      fclose ( $fp );
    }

    $redhat .= " (" . PHP_OS . ")";
    if (defined ( "DB_HOST" )) {
      G::LoadClass ( 'net' );
      G::LoadClass ( 'dbConnections' );
      $dbNetView = new NET ( DB_HOST );
      $dbNetView->loginDbServer ( DB_USER, DB_PASS );

      $dbConns = new dbConnections ( '' );
      $availdb = '';
      foreach ( $dbConns->getDbServicesAvailables () as $key => $val ) {
        if ($availdb != '')
          $availdb .= ', ';
        $availdb .= $val ['name'];
      }

      try {

        $sMySQLVersion = $dbNetView->getDbServerVersion ( DB_ADAPTER );

      }
      catch ( Exception $oException ) {
        $sMySQLVersion = '?????';
      }
    }

    $sysSection = G::loadTranslation('ID_SYSTEM_INFO');
    $pmSection  = 'ProcessMaker Information';

    $properties = array();
    $ee = class_exists('pmLicenseManager') ? " - Enterprise Edition" : '';
    $properties[] = array('ProcessMaker Ver.', System::getVersion() . $ee, $pmSection);
    $properties[] = array('Operating System', $redhat, $sysSection);
    $properties[] = array('Time Zone', (defined('TIME_ZONE')) ? TIME_ZONE : "Unknown", $sysSection);
    $properties[] = array('Web Server', getenv ( 'SERVER_SOFTWARE'), $sysSection);
    $properties[] = array('Server Name', getenv ( 'SERVER_NAME'), $pmSection);
    $properties[] = array('Server IP Address', $this->lookup ($ip), $sysSection);
    $properties[] = array('PHP Version', phpversion(), $sysSection);

    if (defined ( "DB_HOST" )) {
      $properties[] = array('Data Base', $dbNetView->dbName(DB_ADAPTER) . ' (Version ' . $sMySQLVersion . ')', $pmSection);
      $properties[] = array('Data Base Server', DB_HOST, $pmSection);
      $properties[] = array('Data Base Name', DB_NAME, $pmSection);
      $properties[] = array('Available DB Engines', $availdb, $sysSection);
    }
    else {
      $properties[] = array('Data Base', "Not defined", $pmSection);
      $properties[] = array('Data Base Server', "Not defined", $pmSection);
      $properties[] = array('Data Base Name', "Not defined", $pmSection);
      $properties[] = array('Available DB Engines', "Not defined", $sysSection);
    }

    $properties[] = array('Workspace', defined ( "SYS_SYS" ) ? SYS_SYS : "Not defined", $pmSection);


    $properties[] = array('Server Protocol', getenv ( 'SERVER_PROTOCOL'), $sysSection);
    $properties[] = array('Server Port', getenv ( 'SERVER_PORT'), $sysSection);
    //$properties[] = array('Remote Host', getenv ( 'REMOTE_HOST'), $sysSection);
    $properties[] = array('Server Addr.', getenv ( 'SERVER_ADDR'), $sysSection);
    $properties[] = array('User\'s Browser', getenv ( 'HTTP_USER_AGENT'), $sysSection);


    return $properties;
  }

  private function lookup( $target ) {
    global $ntarget;
    $msg = $target . ' => ';
    //if (eregi ( '[a-zA-Z]', $target ))
    if (preg_match( '[a-zA-Z]', $target )) //Made compatible to PHP 5.3
      $ntarget = gethostbyname ( $target );
    else
      $ntarget = gethostbyaddr ( $target );
    $msg .= $ntarget;
    return ($msg);
  }
}