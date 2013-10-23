<?php

/*****************************************************************************************
 * X2CRM Open Source Edition is a customer relationship management program developed by
 * X2Engine, Inc. Copyright (C) 2011-2013 X2Engine Inc.
 * 
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License version 3 as published by the
 * Free Software Foundation with the addition of the following permission added
 * to Section 15 as permitted in Section 7(a): FOR ANY PART OF THE COVERED WORK
 * IN WHICH THE COPYRIGHT IS OWNED BY X2ENGINE, X2ENGINE DISCLAIMS THE WARRANTY
 * OF NON INFRINGEMENT OF THIRD PARTY RIGHTS.
 * 
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE.  See the GNU Affero General Public License for more
 * details.
 * 
 * You should have received a copy of the GNU Affero General Public License along with
 * this program; if not, see http://www.gnu.org/licenses or write to the Free
 * Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA
 * 02110-1301 USA.
 * 
 * You can contact X2Engine, Inc. P.O. Box 66752, Scotts Valley,
 * California 95067, USA. or at email address contact@x2engine.com.
 * 
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 * 
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "Powered by
 * X2Engine" logo. If the display of the logo is not reasonably feasible for
 * technical reasons, the Appropriate Legal Notices must display the words
 * "Powered by X2Engine".
 *****************************************************************************************/

/**
 * X2CRM command line updater
 * 
 * @package X2CRM.commands
 * @author Demitri Morgan <demitri@x2engine.com>
 */
class UpdateCommand extends CConsoleCommand {

    public function beforeAction($action, $params){
        $this->attachBehaviors(array(
            'UpdaterBehavior' => array(
                'class' => 'application.components.UpdaterBehavior',
                'isConsole' => true,
                'scenario' => 'update'
            )
        ));
        $this->requireDependencies();
        set_exception_handler('UpdaterBehavior::respondWithException');
        set_error_handler('UpdaterBehavior::respondWithError');
        return parent::beforeAction($action, $params);
    }

    public function actionIndex(){
        echo $this->help;
    }

    /**
     * Update the application.
     * @param int $force "force" parameter sent to {@link runOperation}
     * @param int $backup "backup" parameter sent to {@link runOperation}
     */
    public function actionApp($force = 0,$backup = 1) {
        // Check updater version, update updater itself, etc.
        $this->runOperation('update',(bool) $force, (bool) $backup);
        return 0;
    }

    /**
     * Performs registration and upgrades the application to a different edition.
     *
     * @param type $key Product key
     * @param type $firstName First name
     * @param type $lastName Last name
     * @param type $email Email address
     * @param bool $force Same as the $force argument of {@link actionApp()}
     */
    public function actionUpgrade($key,$firstName,$lastName,$email,$force=0,$backup=1) {
        $this->uniqueId = $key;
        // Check for curl:
        if(!$this->requirements['extensions']['curl'])
            $this->output(Yii::t('admin','Cannot proceed; cURL extension is required for registration.'),1,1);
        // Let's see if we're clear to proceed first:
        $ch = curl_init($this->updateServer.'/installs/registry/register');
        curl_setopt_array($ch, array(
            CURLOPT_POST => 1,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_POSTFIELDS => array(
                'firstName' => $firstName,
                'lastName' => $lastName,
                'email' => $email,
                'unique_id' => $uid
            ),
        ));
        $cr = json_curl_exec($ch);


        // Now proceed:
        $this->runOperation('upgrade',(bool) $force, (bool) $backup);
    }

    /**
     * Runs the actual update/upgrade.
     * 
     * @param string $scenario The scenario (update or upgrade)
     * @param bool $force False to halt on encountering any
     *  compatibility issues; true to continue through issues
     * @param bool $backup If enabled: create database backup before running
     *  operations, and restore to the backup if operations fail.
     */
    public function runOperation($scenario,$force=false,$backup=true) {
        $this->scenario = $scenario;
        $unpacked = $this->checkIf('packageExists',false);
        if($this->checkIf('packageApplies',false)) {
            // All the data is here and ready to go
            
        } else if($unpacked) {
            // A package is present but cannot be used.
            // 
            // Re-invoke the check method to throw the necessary exception, so
            // that its output can be captured/displayed/logged.
            $this->checkIf('packageApplies');
        } else {
            // No existing package waiting is present.
            // 
            // Prepare for update from square one by first doing an updater
            // version check:
            $this->runUpdateUpdater();
            // Check version:
            $latestVersion = $this->checkUpdates(true);
            if(version_compare($this->configVars['version'], $latestVersion) >= 0) {
                if($scenario != 'upgrade') {
                    $this->output(Yii::t('admin', 'X2CRM is at the latest version!'));
                    Yii::app()->end();
                }
            } else if($scenario == 'upgrade') {
                $this->output(Yii::t('admin',"Before upgrading, you must update to the latest version ({latestver}). ",array('{latestVer}'=>$latestVersion)),1,1);
            }
            $data = $this->getUpdateData();
            if(array_key_exists('errors', $data)){
                // The update server doesn't like us.
                $this->output($data['errors'], 1,1);
            }
            $this->manifest = $data;
        }

        // Check compatibility status:
        $this->output($this->renderCompatibilityMessages());
        if(!$this->compatibilityStatus['allClear'] && !$force) {
            Yii::app()->end();
        }

        // Download and unpack the package:
        if(!$unpacked) {
            $this->downloadPackage();
            $this->unpack();
            $this->checkIf('packageApplies');
            if(!((bool) $this->files) || $this->filesStatus[UpdaterBehavior::FILE_CORRUPT] > 0 || $this->filesStatus[UpdaterBehavior::FILE_MISSING] > 0) {
                $this->output(Yii::t('admin','Could not apply package. {n_mis} files are missing, {n_cor} are corrupt', array(
                            '{n_mis}' => $this->filesStatus[UpdaterBehavior::FILE_MISSING],
                            '{n_cor}' => $this->filesStatus[UpdaterBehavior::FILE_CORRUPT]
                        )), 1, 1);
            }
        }

        // Backup
        if($backup)
            $this->makeDatabaseBackup();

        // Run
        $this->enactChanges($backup);

        $this->output(Yii::t('admin','All done.'));
    }


    /**
     *
     * @return int 1 to indicate that a self-update was performed; 0 to indicate
     *  that the updater utility is already the latest version.
     */
    public function runUpdateUpdater() {
        $config = $this->configVars;
        extract($config);
        $status = 0;
        $latestUpdaterVersion = $this->getLatestUpdaterVersion();
        if($latestUpdaterVersion){
            if(version_compare($updaterVersion,$latestUpdaterVersion) < 0 && $autoRefresh){
                $classes = $this->updateUpdater($latestUpdaterVersion);
                if(empty($classes)){
                    $this->output(Yii::t('admin', 'The updater is now up-to-date and compliant with the updates server.'));
                } else {
                    $this->output(Yii::t('admin', 'One or more dependencies of AdminController are missing and could not be automatically retrieved. They are {classes}', array('{classes}' => implode(', ', $classes))),1,1);
                }
                Yii::app()->end();
            } else {
                $this->output(Yii::t('admin','The updater is up-to-date and safe to use.'));
                return;
            }
        }else{
            if(!$this->requirements['environment']['updates_connection']) {
                $this->output(Yii::t('admin','Could not connect to the updates server, or an error occurred on the updates server.').' '.(
                        $this->requirements['extensions']['curl'] || $this->requirements['environment']['allow_url_fopen']
                        ? ''
                        : Yii::t('admin','Note, this system does not permit outbound HTTP requests via PHP.')
                        ),1,1);
            }
        }
    }

}

?>
