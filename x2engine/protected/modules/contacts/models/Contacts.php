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

Yii::import('application.models.X2Model');

/**
 * This is the model class for table "x2_contacts".
 *
 * @package X2CRM.modules.contacts.models
 */
class Contacts extends X2Model {

	public $name;

	/**
	 * Returns the static model of the specified AR class.
	 * @return Contacts the static model class
	 */
	public static function model($className=__CLASS__) { return parent::model($className); }

	/**
	 * @return string the associated database table name
	 */
	public function tableName() { return 'x2_contacts'; }

	public function behaviors() {
		return array_merge(parent::behaviors(),array(
			'X2LinkableBehavior'=>array(
				'class'=>'X2LinkableBehavior',
				'module'=>'contacts',
			),
			'ERememberFiltersBehavior' => array(
				'class'=>'application.components.ERememberFiltersBehavior',
				'defaults'=>array(),
				'defaultStickOnClear'=>false
			),
		));
	}

    public function rules () {
        $parentRules = parent::rules ();
        $parentRules[] = array (
            'firstName,lastName', 'required', 'on' => 'webForm');
        return $parentRules;
    }

	/**
	 * Sets the name field (full name) on record lookup
	 */
	public function afterFind() {
		parent::afterFind();

		if(isset(Yii::app()->params->admin)) {
			$admin=Yii::app()->params->admin;
			if(!empty($admin->contactNameFormat)) {
				$str = $admin->contactNameFormat;
				$str = str_replace('firstName',$this->firstName,$str);
				$str = str_replace('lastName',$this->lastName,$str);
			} else {
				$str = $this->firstName.' '.$this->lastName;
			}
			if($admin->properCaseNames)
				$str = $this->ucwords_specific($str,array('-',"'",'.'),'UTF-8');

			$this->name = $str;
		}
		if($this->trackingKey === null) {
			$this->trackingKey = self::getNewTrackingKey();
			$this->update(array('trackingKey'));
		}
	}

	/**
	 * Sets the name field (full name) before saving
	 * @return boolean whether or not to save
	 */
	public function beforeSave() {
		if(isset(Yii::app()->params->admin)) {
			$admin = Yii::app()->params->admin;
			if(!empty($admin->contactNameFormat)) {
				$str = $admin->contactNameFormat;
				$str = str_replace('firstName',$this->firstName,$str);
				$str = str_replace('lastName',$this->lastName,$str);
			} else {
				$str = $this->firstName.' '.$this->lastName;
			}
			if($admin->properCaseNames)
				$str = $this->ucwords_specific($str,array('-',"'",'.'),'UTF-8');

			$this->name = $str;
		}
		if($this->trackingKey === null) {
			$this->trackingKey = self::getNewTrackingKey();
		}


		return parent::beforeSave();
	}

	/**
	 * Responds when {@link X2Model::afterUpdate()} is called (record saved, but
	 * not a new record). Sends a notification to anyone subscribed to this contact.
	 *
	 * Before executing this, the model must check whether the contact has the
	 * "changelog" behavior. That is because the behavior is disabled
	 * when checking for duplicates in {@link ContactsController}
	 */
	public function afterUpdate() {
		if (!Yii::app()->params->noSession && $this->scenario != 'noChangelog') {
			// send subscribe emails if anyone has subscribed to this contact
			$result = Yii::app()->db->createCommand()
					->select('user_id')
					->from('x2_subscribe_contacts')
					->where('contact_id=:id', array(':id' => $this->id))
					->queryColumn();

			$datetime = Formatter::formatLongDateTime(time());
			$modelLink = CHtml::link($this->name, Yii::app()->controller->createAbsoluteUrl('/contacts/' . $this->id));
			$subject = 'X2CRM: ' . $this->name . ' updated';
			$message = "Hello,<br>\n<br>\n";
			$message .= 'You are receiving this email because you are subscribed to changes made to the contact ' . $modelLink . ' in X2CRM. ';
			$message .= 'The following changes were made on ' . $datetime . ":<br>\n<br>\n";

			foreach ($this->getChanges() as $attribute => $change) {
				if ($attribute != 'lastActivity') {
					$old = $change[0] == '' ? '-----' : $change[0];
					$new = $change[1] == '' ? '-----' : $change[1];
					$label = $this->getAttributeLabel($attribute);
					$message .= "$label: $old => $new<br>\n";
				}
			}

			$message .="<br>\nYou can unsubscribe to these messages by going to $modelLink and clicking Unsubscribe.<br>\n<br>\n";

			$adminProfile = Yii::app()->params->adminProfile;;
			foreach ($result as $subscription) {
                $subscription=array();
                if(isset($subscription['user_id'])){
                    $profile = X2Model::model('Profile')->findByPk($subscription['user_id']);
                    if ($profile && $profile->emailAddress && $adminProfile && $adminProfile->emailAddress) {
                        $to = array('to'=>array(array($profile->fullName, $profile->emailAddress)));
                        Yii::app()->controller->sendUserEmail($to, $subject, $message,null,Credentials::$sysUseId['systemNotificationEmail']);
                    }
                }
			}
		}


		parent::afterUpdate();
	}

	/**
	 * Returns full human-readable address, using all available address fields
	 */
	public function getCityAddress() {
		$address = '';
		if(!empty($this->address)){
			$address.=$this->address." ";
		}
		if(!empty($this->city))
			$address .= $this->city . ', ';

		if(!empty($this->state))
			$address .= $this->state . ' ';

		if(!empty($this->zipcode))
			$address .= $this->zipcode . ' ';

		if(!empty($this->country))
			$address .= $this->country;

		return $address;
	}

	public static function getNames() {

		$criteria = $this->getAccessCriteria();

        // $condition = 'visibility="1" OR assignedTo="Anyone"  OR assignedTo="'.Yii::app()->user->getName().'"';
		// /* x2temp */
		// $groupLinks = Yii::app()->db->createCommand()->select('groupId')->from('x2_group_to_user')->where('userId='.Yii::app()->user->getId())->queryColumn();
		// if(!empty($groupLinks))
			// $condition .= ' OR assignedTo IN ('.implode(',',$groupLinks).')';

		// $condition .= 'OR (visibility=2 AND assignedTo IN
			// (SELECT username FROM x2_group_to_user WHERE groupId IN
				// (SELECT groupId FROM x2_group_to_user WHERE userId='.Yii::app()->user->getId().')))';
		$contactArray = X2Model::model('Contacts')->findAll($condition);
		$names=array(0=>'None');
		foreach($contactArray as $user){
			$first = $user->firstName;
			$last = $user->lastName;
			$name = $first . ' ' . $last;
			$names[$user->id]=$name;
		}
		return $names;
	}

	/**
	 *	Returns all public contacts.
	 *	@return $names An array of strings containing the names of contacts.
	 */
	public static function getAllNames() {
		$contactArray = X2Model::model('Contacts')->findAll($condition='visibility=1');
		$names=array(0=>'None');
		foreach($contactArray as $user){
			$first = $user->firstName;
			$last = $user->lastName;
			$name = $first . ' ' . $last;
			$names[$user->id]=$name;
		}
		return $names;
	}

	public static function getContactLinks($contacts) {
		if(!is_array($contacts))
			$contacts = explode(' ',$contacts);

		$links = array();
		foreach($contacts as &$id){
			if($id !=0 ) {
				$model = X2Model::model('Contacts')->findByPk($id);
                if(isset($model))
                    $links[] = CHtml::link($model->name,array('/contacts/contacts/view','id'=>$id));
				//$links.=$link.', ';

			}
		}
		//$links=substr($links,0,strlen($links)-2);
		return implode(', ',$links);
	}

	public static function getMailingList($criteria) {

		$mailingList=array();

		$arr=X2Model::model('Contacts')->findAll();
		foreach($arr as $contact){
			$i=preg_match("/$criteria/i",$contact->backgroundInfo);
			if($i>=1){
				$mailingList[]=$contact->email;
			}
		}
		return $mailingList;
	}

	public function searchAll() {
		$criteria = new CDbCriteria;
		// $condition = 'visibility="1" OR assignedTo="Anyone" OR assignedTo="'.Yii::app()->user->getName().'"';
		// $parameters = array('limit'=>ceil(ProfileChild::getResultsPerPage()));

		// $groupLinks = Yii::app()->db->createCommand()->select('groupId')->from('x2_group_to_user')->where('userId='.Yii::app()->user->getId())->queryColumn();
		// if(!empty($groupLinks))
			// $condition .= ' OR assignedTo IN ('.implode(',',$groupLinks).')';

		// $condition .= ' OR (visibility=2 AND assignedTo IN
			// (SELECT username FROM x2_group_to_user WHERE groupId IN
			// (SELECT groupId FROM x2_group_to_user WHERE userId='.Yii::app()->user->getId().')))';

        // if(Yii::app()->user->getName()!='admin' && !Yii::app()->params->isAdmin)
            // $parameters['condition']=$condition;
		// $criteria->scopes=array('findAll'=>array($parameters));

		if(isset($_GET['tagField']) && !empty($_GET['tagField'])) {	// process the tags filter
            
            //remove any spaces around commas, then explode to array
			$tags = explode(',',preg_replace('/\s?,\s?/',',',trim($_GET['tagField'])));	
            $inQuery = array ();
            $params = array ();
			for($i=0; $i<count($tags); $i++) {
				if(empty($tags[$i])) {
					unset($tags[$i]);
					$i--;
					continue;
				} else {
					if($tags[$i][0] != '#') {
						$tags[$i] = '#'.$tags[$i];
                    }
                    $inQuery[] = 'b.tag = :'.$i;
                    $params[':'.$i] = $tags[$i];
					//$tags[$i] = 'b.tag = "'.$tags[$i].'"';
				}
			}
			// die($str);
			//$tagConditions = implode(' OR ',$tags);
			$tagConditions = implode(' OR ',$inQuery);

			$criteria->distinct = true;
			$criteria->join .= ' RIGHT JOIN x2_tags b ON (b.itemId=t.id AND b.type="Contacts" '.
                'AND ('.$tagConditions.'))';
            $criteria->condition='t.id IS NOT NULL';
            $criteria->order='b.timestamp DESC';
            $criteria->params = $params;
		}
		return $this->searchBase($criteria);
	}

	public function searchMyContacts() {
		$criteria = new CDbCriteria;

		$accessLevel = Yii::app()->user->checkAccess('ContactsView')? 1 : 0;
		$conditions=$this->getAccessConditions($accessLevel);
        foreach($conditions as $arr){
            $criteria->addCondition($arr['condition'],$arr['operator']);
        }

		// $condition = 'assignedTo="'.Yii::app()->user->getName().'"';
		// $parameters=array('limit'=>ceil(ProfileChild::getResultsPerPage()));

		// $parameters['condition']=$condition;
		// $criteria->scopes=array('findAll'=>array($parameters));

		return $this->searchBase($criteria);
	}

	public function searchNewContacts() {
		$criteria=new CDbCriteria;
		// $condition = 'assignedTo="'.Yii::app()->user->getName().'" AND createDate > '.mktime(0,0,0);
		$condition = 't.createDate > '.mktime(0,0,0);
		$accessLevel = Yii::app()->user->checkAccess('ContactsView')? 1 : 0;
        $conditions=$this->getAccessConditions($accessLevel);
        foreach($conditions as $arr){
            $criteria->addCondition($arr['condition'],$arr['operator']);
        }

		$parameters=array('limit'=>ceil(ProfileChild::getResultsPerPage()));

		$parameters['condition']=$condition;
		$criteria->scopes=array('findAll'=>array($parameters));

		return $this->searchBase($criteria);
	}


	public function search() {
		$criteria = new CDbCriteria;
		// $condition = 'assignedTo="'.Yii::app()->user->getName().'"';
		// $parameters = array('limit'=>ceil(ProfileChild::getResultsPerPage()));
		/* x2temp */

		// if(Yii::app()->params->isAdmin)
			// $accessLevel = 3;
		// elseif(Yii::app()->user->checkAccess('ContactsView'))
			// $accessLevel = 2;
		// elseif(Yii::app()->user->checkAccess('ContactsViewPrivate'))
			// $accessLevel = 1;

		// $condition = Yii::app()->user->searchAccessConditions($accessLevel);

		// $groupLinks = Yii::app()->db->createCommand()->select('groupId')->from('x2_group_to_user')->where('userId='.Yii::app()->user->getId())->queryColumn();
		// if(!empty($groupLinks))
			// $condition .= ' OR assignedTo IN ('.implode(',',$groupLinks).')';
		/* end x2temp */
		// $parameters['condition'] = $condition;
		// $criteria->scopes=array('findAll'=>array($parameters));

		return $this->searchBase($criteria);
	}

	public function searchAdmin() {
		$criteria=new CDbCriteria;
		return $this->searchBase($criteria);
	}

	public function searchAccount($id) {
		$criteria = new CDbCriteria;
		$criteria->compare('company',$id);

		return $this->searchBase($criteria);
	}

	/**
	 * Returns a DataProvider for all the contacts in the specified list,
	 * using this Contact model's attributes as a search filter
	 */
	public function searchList($id, $pageSize=null) {
		$list = X2List::model()->findByPk($id);

		if(isset($list)) {
			$search = $list->queryCriteria();


			$this->compareAttributes($search);

			return new SmartDataProvider('Contacts',array(
				'criteria'=>$search,
				'sort'=>array(
					'defaultOrder'=>'t.lastUpdated DESC'	// true = ASC
				),
				'pagination'=>array(
					'pageSize'=>isset($pageSize)? $pageSize : ProfileChild::getResultsPerPage(),
				),
			));

		} else {	//if list is not working, return all contacts
			return $this->searchBase();
		}
	}
    
	/**
	 * Generates a random tracking key and guarantees uniqueness
	 * @return String $key a unique random tracking key
	 */
	public static function getNewTrackingKey() {

		$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

		// try up to 100 times to guess a unique key
		for($i=0; $i<100; $i++) {
			$key = '';
			for($j=0; $j<32; $j++)	// generate a random 32 char alphanumeric string
				$key .= substr($chars,rand(0,strlen($chars)-1), 1);

			if(X2Model::model('Contacts')->exists('trackingKey="'.$key.'"'))	// check if this key is already used
				continue;
			else
				return $key;
		}
		return null;
	}

    function ucwords_specific ($string, $delimiters = '', $encoding = NULL)
    {

        if ($encoding === NULL) { $encoding = mb_internal_encoding();}

        if (is_string($delimiters))
        {
            $delimiters =  str_split( str_replace(' ', '', $delimiters));
        }

        $delimiters_pattern1 = array();
        $delimiters_replace1 = array();
        $delimiters_pattern2 = array();
        $delimiters_replace2 = array();
        foreach ($delimiters as $delimiter)
        {
            $ucDelimiter=$delimiter;
            $delimiter=strtolower($delimiter);
            $uniqid = uniqid();
            $delimiters_pattern1[]   = '/'. preg_quote($delimiter) .'/';
            $delimiters_replace1[]   = $delimiter.$uniqid.' ';
            $delimiters_pattern2[]   = '/'. preg_quote($ucDelimiter.$uniqid.' ') .'/';
            $delimiters_replace2[]   = $ucDelimiter;
            $delimiters_cleanup_replace1[]   = '/'. preg_quote($delimiter.$uniqid).' ' .'/';
            $delimiters_cleanup_pattern1[]   = $delimiter;
        }
        $return_string = mb_strtolower($string, $encoding);
        //$return_string = $string;
        $return_string = preg_replace($delimiters_pattern1, $delimiters_replace1, $return_string);

        $words = explode(' ', $return_string);

        foreach ($words as $index => $word)
        {
            $words[$index] = mb_strtoupper(mb_substr($word, 0, 1, $encoding), $encoding).mb_substr($word, 1, mb_strlen($word, $encoding), $encoding);
        }
        $return_string = implode(' ', $words);

        $return_string = preg_replace($delimiters_pattern2, $delimiters_replace2, $return_string);
        $return_string = preg_replace($delimiters_cleanup_replace1, $delimiters_cleanup_pattern1, $return_string);

        return $return_string;
    }
}
