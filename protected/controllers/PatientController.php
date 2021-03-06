<?php
/**
 * OpenEyes
 *
 * (C) Moorfields Eye Hospital NHS Foundation Trust, 2008-2011
 * (C) OpenEyes Foundation, 2011-2013
 * This file is part of OpenEyes.
 * OpenEyes is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * OpenEyes is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License along with OpenEyes in a file titled COPYING. If not, see <http://www.gnu.org/licenses/>.
 *
 * @package OpenEyes
 * @link http://www.openeyes.org.uk
 * @author OpenEyes <info@openeyes.org.uk>
 * @copyright Copyright (c) 2008-2011, Moorfields Eye Hospital NHS Foundation Trust
 * @copyright Copyright (c) 2011-2013, OpenEyes Foundation
 * @license http://www.gnu.org/licenses/gpl-3.0.html The GNU General Public License V3.0
 */

Yii::import('application.controllers.*');

class PatientController extends BaseController
{
	public $layout = '//layouts/main';
	public $renderPatientPanel = true;
	public $patient;
	public $firm;
	public $editable;
	public $editing;
	public $event;
	public $event_type;
	public $title;
	public $event_type_id;
	public $episode;
	public $current_episode;
	public $event_tabs = array();
	public $event_actions = array();
	public $episodes = array();

	public function accessRules()
	{
		return array(
			array('allow',
				'actions' => array('search', 'view'),
				'users' => array('@')
			),
			array('allow',
				'actions' => array('episode', 'episodes', 'hideepisode', 'showepisode'),
				'roles' => array('OprnViewClinical'),
			),
			array('allow',
				'actions' => array('verifyAddNewEpisode', 'addNewEpisode'),
				'roles' => array('OprnCreateEpisode'),
			),
			array('allow',
				'actions' => array('updateepisode'),  // checked in action
				'users' => array('@'),
			),
			array('allow',
				'actions' => array('possiblecontacts', 'associatecontact', 'unassociatecontact', 'getContactLocation', 'institutionSites', 'validateSaveContact', 'addContact', 'validateEditContact', 'editContact', 'sendSiteMessage'),
				'roles' => array('OprnEditContact'),
			),
			array('allow',
				'actions' => array('addAllergy', 'removeAllergy'),
				'roles' => array('OprnEditAllergy'),
			),
			array('allow',
				'actions' => array('adddiagnosis', 'validateAddDiagnosis', 'removediagnosis'),
				'roles' => array('OprnEditOtherOphDiagnosis'),
			),
			array('allow',
				'actions' => array('editOphInfo'),
				'roles' => array('OprnEditOphInfo'),
			),
			array('allow',
				'actions' => array('addPreviousOperation', 'getPreviousOperation', 'removePreviousOperation'),
				'roles' => array('OprnEditPreviousOperation'),
			),
			array('allow',
				'actions' => array('addFamilyHistory', 'removeFamilyHistory'),
				'roles' => array('OprnEditFamilyHistory')
			),
			array('allow',
				'actions' => array('editSocialHistory', 'editSocialHistory'),
				'roles' => array('OprnEditSocialHistory')
			),
		);
	}

	protected function beforeAction($action)
	{
		parent::storeData();

		$this->firm = Firm::model()->findByPk($this->selectedFirmId);

		if (!isset($this->firm)) {
			// No firm selected, reject
			throw new CHttpException(403, 'You are not authorised to view this page without selecting a firm.');
		}

		return parent::beforeAction($action);
	}

	/**
	 * Displays a particular model.
	 * @param integer $id the ID of the model to be displayed
	 */
	public function actionView($id)
	{
		Yii::app()->assetManager->registerScriptFile('js/patientSummary.js');

		$this->patient = $this->loadModel($id);

		$tabId = !empty($_GET['tabId']) ? $_GET['tabId'] : 0;
		$eventId = !empty($_GET['eventId']) ? $_GET['eventId'] : 0;

		$episodes = $this->patient->episodes;
		// TODO: verify if ordered_episodes complete supercedes need for unordered $episodes
		$ordered_episodes = $this->patient->getOrderedEpisodes();

		$legacyepisodes = $this->patient->legacyepisodes;
		// NOTE that this is not being used in the render
		$supportserviceepisodes = $this->patient->supportserviceepisodes;

		Audit::add('patient summary','view',$id);

		$this->logActivity('viewed patient');

		$episodes_open = 0;
		$episodes_closed = 0;

		foreach ($episodes as $episode) {
			if ($episode->end_date === null) {
				$episodes_open++;
			} else {
				$episodes_closed++;
			}
		}

		$this->jsVars['currentContacts'] = $this->patient->currentContactIDS();

		$this->breadcrumbs=array(
			$this->patient->first_name.' '.$this->patient->last_name. '('.$this->patient->hos_num.')',
		);

		$this->render('view', array(
			'tab' => $tabId,
			'event' => $eventId,
			'episodes' => $episodes,
			'ordered_episodes' => $ordered_episodes,
			'legacyepisodes' => $legacyepisodes,
			'episodes_open' => $episodes_open,
			'episodes_closed' => $episodes_closed,
			'firm' => $this->firm,
			'supportserviceepisodes' => $supportserviceepisodes,
		));
	}

	public function actionSearch()
	{
		// Check that we have a valid set of search criteria
		$search_terms = array(
				'hos_num' => null,
				'nhs_num' => null,
				'first_name' => null,
				'last_name' => null,
		);
		foreach ($search_terms as $search_term => $search_value) {
			if (isset($_GET[$search_term]) && $search_value = trim($_GET[$search_term])) {

				// Pad hos_num
				if ($search_term == 'hos_num') {
					$search_value = sprintf('%07s',$search_value);
				}

				$search_terms[$search_term] = $search_value;
			}
		}
		// if we are on a dev environment, this allows more flexible search terms (i.e. just a first name or surname - useful for testing
		// the multiple search results view. If we are live, enforces controls over search terms.
		if (!YII_DEBUG && !$search_terms['hos_num'] && !$search_terms['nhs_num'] && !($search_terms['first_name'] && $search_terms['last_name'])) {
			Yii::app()->user->setFlash('warning.invalid-search', 'Please enter a valid search.');
			$this->redirect(Yii::app()->homeUrl);
		}

		 $search_terms = CHtml::encodeArray($search_terms);

		switch (@$_GET['sort_by']) {
			case 0:
				$sort_by = 'hos_num*1';
				break;
			case 1:
				$sort_by = 'title';
				break;
			case 2:
				$sort_by = 'first_name';
				break;
			case 3:
				$sort_by = 'last_name';
				break;
			case 4:
				$sort_by = 'dob';
				break;
			case 5:
				$sort_by = 'gender';
				break;
			case 6:
				$sort_by = 'nhs_num*1';
				break;
			default:
				$sort_by = 'hos_num*1';
		}

		$sort_dir = (@$_GET['sort_dir'] == 0 ? 'asc' : 'desc');
		$page_num = (integer) @$_GET['page_num'];
		$page_size = 20;

		$model = new Patient();
		$model->hos_num = $search_terms['hos_num'];
		$model->nhs_num = $search_terms['nhs_num'];
		$dataProvider = $model->search(array(
			'currentPage' => $page_num,
			'pageSize' => $page_size,
			'sortBy' => $sort_by,
			'sortDir'=> $sort_dir,
			'first_name' => CHtml::decode($search_terms['first_name']),
			'last_name' => CHtml::decode($search_terms['last_name']),
		));
		$nr = $model->search_nr(array(
			'first_name' => CHtml::decode($search_terms['first_name']),
			'last_name' => CHtml::decode($search_terms['last_name']),
		));

		if ($nr == 0) {
			Audit::add('search','search-results',implode(',',$search_terms) ." : No results");

			$message = 'Sorry, no results ';
			if ($search_terms['hos_num']) {
				$message .= 'for Hospital Number <strong>"'.$search_terms['hos_num'].'"</strong>';
			} elseif ($search_terms['nhs_num']) {
				$message .= 'for NHS Number <strong>"'.$search_terms['nhs_num'].'"</strong>';
			} elseif ($search_terms['first_name'] && $search_terms['last_name']) {
				$message .= 'for Patient Name <strong>"'.$search_terms['first_name'] . ' ' . $search_terms['last_name'].'"</strong>';
			} else {
				$message .= 'found for your search.';
			}
			Yii::app()->user->setFlash('warning.no-results', $message);

			$this->redirect(Yii::app()->homeUrl);

		} elseif ($nr == 1) {
			foreach ($dataProvider->getData() as $item) {
				$this->redirect(array('patient/view/' . $item->id));
			}
		} else {
			$this->renderPatientPanel = false;
			$pages = ceil($nr/$page_size);
			$this->render('results', array(
				'data_provider' => $dataProvider,
				'pages' => $pages,
				'page_num' => $page_num,
				'items_per_page' => $page_size,
				'total_items' => $nr,
				'search_terms' => $search_terms,
				'sort_by' => (integer) @$_GET['sort_by'],
				'sort_dir' => (integer) @$_GET['sort_dir']
			));
		}

	}

	public function actionEpisodes()
	{
		$this->layout = '//layouts/events_and_episodes';
		$this->patient = $this->loadModel($_GET['id']);

		$episodes = $this->patient->episodes;
		$legacyepisodes = $this->patient->legacyepisodes;
		$site = Site::model()->findByPk(Yii::app()->session['selected_site_id']);

		if (!$current_episode = $this->patient->getEpisodeForCurrentSubspecialty()) {
			$current_episode = empty($episodes) ? false : $episodes[0];
			if (!empty($legacyepisodes)) {
				$criteria = new CDbCriteria;
				$criteria->compare('episode_id',$legacyepisodes[0]->id);
				$criteria->order = 'event_date desc, created_date desc';

				foreach (Event::model()->findAll($criteria) as $event) {
					if (in_array($event->eventType->class_name,Yii::app()->modules) && (!$event->eventType->disabled)) {
						$this->redirect(array($event->eventType->class_name.'/default/view/'.$event->id));
						Yii::app()->end();
					}
				}
			}
		} elseif ($current_episode->end_date == null) {
			$criteria = new CDbCriteria;
			$criteria->compare('episode_id',$current_episode->id);
			$criteria->order = 'event_date desc, created_date desc';

			if ($event = Event::model()->find($criteria)) {
				$this->redirect(array($event->eventType->class_name.'/default/view/'.$event->id));
				Yii::app()->end();
			}
		} else {
			$current_episode = null;
		}

		$this->current_episode = $current_episode;
		$this->title = 'Episode summary';

		$this->render('episodes', array(
			'title' => empty($episodes) ? '' : 'Episode summary',
			'episodes' => $episodes,
			'site' => $site,
			'cssClass' => 'episodes-list'
		));
	}

	public function actionEpisode($id)
	{
		if (!$this->episode = Episode::model()->findByPk($id)) {
			throw new SystemException('Episode not found: '.$id);
		}

		$this->layout = '//layouts/events_and_episodes';
		$this->patient = $this->episode->patient;

		$episodes = $this->patient->episodes;

		$site = Site::model()->findByPk(Yii::app()->session['selected_site_id']);

		$this->title = 'Episode summary';
		$this->event_tabs = array(
				array(
						'label' => 'View',
						'active' => true,
				)
		);

		if ($this->checkAccess('OprnEditEpisode', $this->firm, $this->episode) && $this->episode->firm) {
			$this->event_tabs[] = array(
					'label' => 'Edit',
					'href' => Yii::app()->createUrl('/patient/updateepisode/'.$this->episode->id),
			);
		}
		$this->current_episode = $this->episode;
		$status = Yii::app()->session['episode_hide_status'];
		$status[$id] = true;
		Yii::app()->session['episode_hide_status'] = $status;

		$this->render('episodes', array(
			'title' => empty($episodes) ? '' : 'Episode summary',
			'episodes' => $episodes,
			'site' => $site,
		));
	}

	public function actionUpdateepisode($id)
	{
		if (!$this->episode = Episode::model()->findByPk($id)) {
			throw new SystemException('Episode not found: '.$id);
		}

		if (!$this->checkAccess('OprnEditEpisode', $this->firm, $this->episode) || isset($_POST['episode_cancel'])) {
			$this->redirect(array('patient/episode/'.$this->episode->id));
			return;
		}

		if (!empty($_POST)) {
			if ((@$_POST['eye_id'] && !@$_POST['DiagnosisSelection']['disorder_id'])) {
				$error = "Please select a disorder for the principal diagnosis";
			} elseif (!@$_POST['eye_id'] && @$_POST['DiagnosisSelection']['disorder_id']) {
				$error = "Please select an eye for the principal diagnosis";
			} else {
				if (@$_POST['eye_id'] && @$_POST['DiagnosisSelection']['disorder_id']) {
					if ($_POST['eye_id'] != $this->episode->eye_id || $_POST['DiagnosisSelection']['disorder_id'] != $this->episode->disorder_id) {
						$this->episode->setPrincipalDiagnosis($_POST['DiagnosisSelection']['disorder_id'],$_POST['eye_id']);
					}
				}

				if ($_POST['episode_status_id'] != $this->episode->episode_status_id) {
					$this->episode->episode_status_id = $_POST['episode_status_id'];

					if (!$this->episode->save()) {
						throw new Exception('Unable to update status for episode '.$this->episode->id.' '.print_r($this->episode->getErrors(),true));
					}
				}

				$this->redirect(array('patient/episode/'.$this->episode->id));
			}
		}

		$this->patient = $this->episode->patient;
		$this->layout = '//layouts/events_and_episodes';

		$episodes = $this->patient->episodes;
		// TODO: verify if ordered_episodes complete supercedes need for unordered $episodes
		$ordered_episodes = $this->patient->getOrderedEpisodes();
		$legacyepisodes = $this->patient->legacyepisodes;
		$supportserviceepisodes = $this->patient->supportserviceepisodes;

		$site = Site::model()->findByPk(Yii::app()->session['selected_site_id']);

		$this->title = 'Episode summary';
		$this->event_tabs = array(
				array(
						'label' => 'View',
						'href' => Yii::app()->createUrl('/patient/episode/'.$this->episode->id),
				),
				array(
						'label' => 'Edit',
						'active' => true,
				),
		);

		$status = Yii::app()->session['episode_hide_status'];
		$status[$id] = true;
		Yii::app()->session['episode_hide_status'] = $status;

		$this->editing = true;

		$this->render('episodes', array(
			'title' => empty($episodes) ? '' : 'Episode summary',
			'episodes' => $episodes,
			'ordered_episodes' => $ordered_episodes,
			'legacyepisodes' => $legacyepisodes,
			'supportserviceepisodes' => $supportserviceepisodes,
			'eventTypes' => EventType::model()->getEventTypeModules(),
			'site' => $site,
			'current_episode' => $this->episode,
			'error' => @$error,
		));
	}

	/**
	 * Returns the data model based on the primary key given in the GET variable.
	 * If the data model is not found, an HTTP exception will be raised.
	 * @param integer $id the ID of the model to be loaded
	 */
	public function loadModel($id)
	{
		$model = Patient::model()->findByPk((int) $id);
		if ($model === null)
			throw new CHttpException(404, 'The requested page does not exist.');
		return $model;
	}

	/**
	 * Performs the AJAX validation.
	 * @param CModel $model the model to be validated
	 */
	protected function performAjaxValidation($model)
	{
		if (isset($_POST['ajax']) && $_POST['ajax'] === 'patient-form') {
			echo CActiveForm::validate($model);
			Yii::app()->end();
		}
	}

	protected function getEventTypeGrouping()
	{
		return array(
			'Examination' => array('visual fields', 'examination', 'question', 'outcome'),
			'Treatments' => array('oct', 'laser', 'operation'),
			'Correspondence' => array('letterin', 'letterout'),
			'Consent Forms' => array(''),
		);
	}

	/**
	 * Perform a search on a model and return the results
	 * (separate function for unit testing)
	 *
	 * @param array $data form data of search terms
	 * @return CDataProvider
	 */
	public function getSearch($data)
	{
		$model = new Patient;
		$model->attributes = $data;
		return $model->search();
	}

	public function getTemplateName($action, $eventTypeId)
	{
		$template = 'eventTypeTemplates' . DIRECTORY_SEPARATOR . $action . DIRECTORY_SEPARATOR . $eventTypeId;

		if (!file_exists(Yii::app()->basePath . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'clinical' . DIRECTORY_SEPARATOR . $template . '.php')) {
			$template = $action;
		}

		return $template;
	}

	/**
	 * Get all the elements for a the current module's event type
	 *
	 * @param $event_type_id
	 * @return array
	 */
	public function getDefaultElements($action, $event_type_id=false, $event=false)
	{
		$etc = new BaseEventTypeController(1);
		$etc->event = $event;
		return $etc->getDefaultElements($action, $event_type_id);
	}

	/**
	 * Get the optional elements for the current module's event type
	 * This will be overriden by the module
	 *
	 * @param $event_type_id
	 * @return array
	 */
	public function getOptionalElements($action, $event=false)
	{
		return array();
	}

	public function actionPossiblecontacts()
	{
		$term = strtolower(trim($_GET['term'])).'%';

		switch (strtolower(@$_GET['filter'])) {
			case 'staff':
				$contacts = User::model()->findAsContacts($term);
				break;
			case 'nonspecialty':
				if (!$specialty = Specialty::model()->find('code=?',array(Yii::app()->params['institution_specialty']))) {
					throw new Exception("Unable to find specialty: ".Yii::app()->params['institution_specialty']);
				}
				$contacts = Contact::model()->findByLabel($term, $specialty->default_title, true, 'person');
				break;
			default:
				$contacts = Contact::model()->findByLabel($term, @$_GET['filter'], false, 'person');
		}

		echo CJavaScript::jsonEncode($contacts);
	}

	public function actionAssociatecontact()
	{
		if (!$patient = Patient::model()->findByPk(@$_GET['patient_id'])) {
			throw new Exception('Patient not found: '.@$_GET['patient_id']);
		}

		if (@$_GET['contact_location_id']) {
			if (!$location = ContactLocation::model()->findByPk(@$_GET['contact_location_id'])) {
				throw new Exception("Can't find contact location: ".@$_GET['contact_location_id']);
			}
			$contact = $location->contact;
		} else {
			if (!$contact = Contact::model()->findByPk(@$_GET['contact_id'])) {
				throw new Exception("Can't find contact: ".@$_GET['contact_id']);
			}
		}

		// Don't assign the patient's own GP
		if ($contact->label == 'General Practitioner') {
			if ($gp = Gp::model()->find('contact_id=?',array($contact->id))) {
				if ($gp->id == $patient->gp_id) {
					return;
				}
			}
		}

		if (isset($location)) {
			if (!$pca = PatientContactAssignment::model()->find('patient_id=? and location_id=?',array($patient->id,$location->id))) {
				$pca = new PatientContactAssignment;
				$pca->patient_id = $patient->id;
				$pca->location_id = $location->id;

				if (!$pca->save()) {
					throw new Exception("Unable to save patient contact assignment: ".print_r($pca->getErrors(),true));
				}
			}
		} else {
			if (!$pca = PatientContactAssignment::model()->find('patient_id=? and contact_id=?',array($patient->id,$contact->id))) {
				$pca = new PatientContactAssignment;
				$pca->patient_id = $patient->id;
				$pca->contact_id = $contact->id;

				if (!$pca->save()) {
					throw new Exception("Unable to save patient contact assignment: ".print_r($pca->getErrors(),true));
				}
			}
		}

		$this->renderPartial('_patient_contact_row',array('pca'=>$pca));
	}

	public function actionUnassociatecontact()
	{
		if (!$pca = PatientContactAssignment::model()->findByPk(@$_GET['pca_id'])) {
			throw new Exception("Patient contact assignment not found: ".@$_GET['pca_id']);
		}

		if (!$pca->delete()) {
			echo "0";
		} else {
			$pca->patient->audit('patient','unassociate-contact');
			echo "1";
		}
	}

	/**
	 * Add patient/allergy assignment
	 *
	 * @throws Exception
	 */
	public function actionAddAllergy()
	{
		if (!empty($_POST)) {
			$patient = $this->fetchModel('Patient', @$_POST['patient_id']);

			if (@$_POST['no_allergies']) {
				$patient->setNoAllergies();
			} else  {
				$allergy = $this->fetchModel('Allergy', @$_POST['allergy_id']);
				$patient->addAllergy($allergy, @$_POST['other'], @$_POST['comments']);
			}
		}

		$this->redirect(array('patient/view/'.$patient->id));
	}

	/**
	 * Remove patient/allergy assignment
	 *
	 * @throws Exception
	 */
	public function actionRemoveAllergy()
	{
		PatientAllergyAssignment::model()->deleteByPk(@$_GET['assignment_id']);
		echo 'success';
	}

	/**
	 * List of allergies
	 */
	public function allergyList()
	{
		$allergy_ids = array();
		foreach ($this->patient->allergies as $allergy) {
			if ($allergy->name != 'Other') $allergy_ids[] = $allergy->id;
		}
		$criteria = new CDbCriteria;
		!empty($allergy_ids) && $criteria->addNotInCondition('id',$allergy_ids);
		$criteria->order = 'name asc';
		return Allergy::model()->active()->findAll($criteria);
	}

	public function actionHideepisode()
	{
		$status = Yii::app()->session['episode_hide_status'];

		if (isset($_GET['episode_id'])) {
			$status[$_GET['episode_id']] = false;
		}

		Yii::app()->session['episode_hide_status'] = $status;
	}

	public function actionShowepisode()
	{
		$status = Yii::app()->session['episode_hide_status'];

		if (isset($_GET['episode_id'])) {
			$status[$_GET['episode_id']] = true;
		}

		Yii::app()->session['episode_hide_status'] = $status;
	}

	private function processFuzzyDate()
	{
		return Helper::padFuzzyDate(@$_POST['fuzzy_year'],@$_POST['fuzzy_month'],@$_POST['fuzzy_day']);
	}

	public function actionAdddiagnosis()
	{
		if (isset($_POST['DiagnosisSelection']['ophthalmic_disorder_id'])) {
			$disorder = Disorder::model()->findByPk(@$_POST['DiagnosisSelection']['ophthalmic_disorder_id']);
		} else {
			$disorder = Disorder::model()->findByPk(@$_POST['DiagnosisSelection']['systemic_disorder_id']);
		}

		if (!$disorder) {
			throw new Exception('Unable to find disorder: '.@$_POST['DiagnosisSelection']['ophthalmic_disorder_id'].' / '.@$_POST['DiagnosisSelection']['systemic_disorder_id']);
		}

		if (!$patient = Patient::model()->findByPk(@$_POST['patient_id'])) {
			throw new Exception('Unable to find patient: '.@$_POST['patient_id']);
		}

		$date = $this->processFuzzyDate();

		if (!$_POST['diagnosis_eye']) {
			if (!SecondaryDiagnosis::model()->find('patient_id=? and disorder_id=? and date=?',array($patient->id,$disorder->id,$date))) {
				$patient->addDiagnosis($disorder->id,null,$date);
			}
		} elseif (!SecondaryDiagnosis::model()->find('patient_id=? and disorder_id=? and eye_id=? and date=?',array($patient->id,$disorder->id,$_POST['diagnosis_eye'],$date))) {
			$patient->addDiagnosis($disorder->id, $_POST['diagnosis_eye'], $date);
		}

		$this->redirect(array('patient/view/'.$patient->id));
	}

	public function actionValidateAddDiagnosis()
	{
		$errors = array();

		if (!$patient = Patient::model()->findByPk(@$_POST['patient_id'])) {
			throw new Exception("Patient not found: ".@$_POST['patient_id']);
		}

		if (isset($_POST['DiagnosisSelection']['ophthalmic_disorder_id'])) {
			$disorder_id = $_POST['DiagnosisSelection']['ophthalmic_disorder_id'];
		} elseif (isset($_POST['DiagnosisSelection']['systemic_disorder_id'])) {
			$disorder_id = $_POST['DiagnosisSelection']['systemic_disorder_id'];
		}

		$sd = new SecondaryDiagnosis;
		$sd->patient_id = $patient->id;
		$sd->date = $this->processFuzzyDate();
		$sd->disorder_id = @$disorder_id;
		$sd->eye_id = @$_POST['diagnosis_eye'];

		$errors = array();

		if (!$sd->validate()) {
			foreach ($sd->getErrors() as $field => $_errors) {
				$errors[$field] = $_errors[0];
			}
		}

		// Check the diagnosis isn't currently set at the episode level for this patient
		foreach ($patient->episodes as $episode) {
			if ($episode->disorder_id == $sd->disorder_id && ($episode->eye_id == $sd->eye_id || $episode->eye_id == 3 || $sd->eye_id == 3)) {
				$errors['disorder_id'] = "The disorder is already set at the episode level for this patient";
			}
		}

		// Check that the date isn't in the future
		if (@$_POST['fuzzy_year'] == date('Y')) {
			if (@$_POST['fuzzy_month'] > date('n')) {
				$errors['date'] = "The date cannot be in the future.";
			} elseif (@$_POST['fuzzy_month'] == date('n')) {
				if (@$_POST['fuzzy_day'] > date('j')) {
					$errors['date'] = "The date cannot be in the future.";
				}
			}
		}

		// Check that the date is valid
		$v = new OEFuzzyDateValidator;
		$v->validateAttribute($sd,'date');

		echo json_encode($errors);
	}

	public function actionRemovediagnosis()
	{
		if (!$patient = Patient::model()->findByPk(@$_GET['patient_id'])) {
			throw new Exception('Unable to find patient: '.@$_GET['patient_id']);
		}

		$patient->removeDiagnosis(@$_GET['diagnosis_id']);

		echo "success";
	}

	public function actionEditOphInfo()
	{
		$cvi_status = PatientOphInfoCviStatus::model()->findByPk(@$_POST['PatientOphInfo']['cvi_status_id']);

		if (!$cvi_status) {
			throw new Exception('invalid cvi status selection:' . @$_POST['PatientOphInfo']['cvi_status_id']);
		}

		if (!$patient = Patient::model()->findByPk(@$_POST['patient_id'])) {
			throw new Exception('Unable to find patient: '.@$_POST['patient_id']);
		}

		$cvi_status_date = $this->processFuzzyDate();

		$result = $patient->editOphInfo($cvi_status, $cvi_status_date);

		echo json_encode($result);
	}

	public function reportDiagnoses($params)
	{
		$patients = array();

		$where = "p.deleted = 0 ";
		$select = "p.id as patient_id, p.hos_num, c.first_name, c.last_name";

		if (empty($params['selected_diagnoses'])) {
			return array('patients'=>array());
		}

		$command = Yii::app()->db->createCommand()
			->from("patient p")
			->join("contact c","p.contact_id = c.id");

		if (!empty($params['principal'])) {
			foreach ($params['principal'] as $i => $disorder_id) {
				$command->join("episode e$i","e$i.patient_id = p.id");
				$command->join("eye eye_e_$i","eye_e_$i.id = e$i.eye_id");
				$command->join("disorder disorder_e_$i","disorder_e_$i.id = e$i.disorder_id");
				$where .= "e$i.disorder_id = $disorder_id and e$i.deleted = 0 and disorder_e_$i.deleted = 0 ";
				$select .= ", e$i.last_modified_date as episode{$i}_date, eye_e_$i.name as episode{$i}_eye, disorder_e_$i.term as episode{$i}_disorder";
			}
		}

		foreach ($params['selected_diagnoses'] as $i => $disorder_id) {
			if (empty($params['principal']) || !in_array($disorder_id,$params['principal'])) {
				$command->join("secondary_diagnosis sd$i","sd$i.patient_id = p.id");
				$command->join("eye eye_sd_$i","eye_sd_$i.id = sd$i.eye_id");
				$command->join("disorder disorder_sd_$i","disorder_sd_$i.id = sd$i.disorder_id");
				$where .= "sd$i.disorder_id = $disorder_id and sd$i.deleted = 0 and disorder_sd_$i.deleted = 0 ";
				$select .= ", sd$i.date as sd{$i}_date, sd$i.eye_id as sd{$i}_eye_id, eye_sd_$i.name as sd{$i}_eye, disorder_sd_$i.term as sd{$i}_disorder";
			}
		}

		$results = array();

		foreach ($command->select($select)->where($where)->queryAll() as $row) {
			$date = $this->reportEarliestDate($row);

			while (isset($results[$date['timestamp']])) {
				$date['timestamp']++;
			}

			$results['patients'][$date['timestamp']] = array(
				'patient_id' => $row['patient_id'],
				'hos_num' => $row['hos_num'],
				'first_name' => $row['first_name'],
				'last_name' => $row['last_name'],
				'date' => $date['date'],
				'diagnoses' => array(),
			);

			foreach ($row as $key => $value) {
				if (preg_match('/^episode([0-9]+)_eye$/',$key,$m)) {
					$results['patients'][$date['timestamp']]['diagnoses'][] = array(
						'eye' => $value,
						'diagnosis' => $row['episode'.$m[1].'_disorder'],
					);
				}
				if (preg_match('/^sd([0-9]+)_eye$/',$key,$m)) {
					$results['patients'][$date['timestamp']]['diagnoses'][] = array(
						'eye' => $value,
						'diagnosis' => $row['sd'.$m[1].'_disorder'],
					);
				}
			}
		}

		ksort($results['patients'], SORT_NUMERIC);

		return $results;
	}

	public function reportEarliestDate($row)
	{
		$dates = array();

		foreach ($row as $key => $value) {
			$value = substr($value,0,10);

			if (preg_match('/_date$/',$key) && !in_array($value,$dates)) {
				$dates[] = $value;
			}
		}

		sort($dates, SORT_STRING);

		if (preg_match('/-00-00$/',$dates[0])) {
			return array(
				'date' => substr($dates[0],0,4),
				'timestamp' => strtotime(substr($dates[0],0,4).'-01-01'),
			);
		} elseif (preg_match('/-00$/',$dates[0])) {
			$date = Helper::getMonthText(substr($dates[0],5,2)).' '.substr($dates[0],0,4);
			return array(
				'date' => $date,
				'timestamp' => strtotime($date),
			);
		}

		return array(
			'date' => date('j M Y',strtotime($dates[0])),
			'timestamp' => strtotime($dates[0]),
		);
	}

	public function actionAddPreviousOperation()
	{
		if (!$patient = Patient::model()->findByPk(@$_POST['patient_id'])) {
			throw new Exception("Patient not found:".@$_POST['patient_id']);
		}

		if (!isset($_POST['previous_operation'])) {
			throw new Exception("Missing previous operation text");
		}

		if (@$_POST['edit_operation_id']) {
			if (!$po = PreviousOperation::model()->findByPk(@$_POST['edit_operation_id'])) {
				$po = new PreviousOperation;
			}
		} else {
			$po = new PreviousOperation;
		}

		$po->patient_id = $patient->id;
		$po->side_id = @$_POST['previous_operation_side'] ? @$_POST['previous_operation_side'] : null;
		$po->operation = @$_POST['previous_operation'];
		$po->date = $this->processFuzzyDate();

		if($po->date == '0000-00-00'){
			$po->date = null;
		}

		if (!$po->save()) {
			echo json_encode($po->getErrors());
			return;
		}

		echo json_encode(array());
	}

	public function actionEditSocialHistory()
	{
		if (!$patient = Patient::model()->findByPk(@$_POST['patient_id'])) {
			throw new Exception("Patient not found:".@$_POST['patient_id']);
		}
		if (!$social_history = SocialHistory::model()->find('patient_id=?',array($patient->id))) {
			$social_history = new SocialHistory();
		}
		$social_history->patient_id = $patient->id;
		$social_history->attributes =$_POST['SocialHistory'];
		if (!$social_history->save()) {
			throw new Exception("Unable to save social history: ".print_r($social_history->getErrors(),true));
		}
		else {
			$this->redirect(array('patient/view/'.$patient->id));
		}

	}

	public function actionAddFamilyHistory()
	{
		if (!$patient = Patient::model()->findByPk(@$_POST['patient_id'])) {
			throw new Exception("Patient not found:".@$_POST['patient_id']);
		}

		if (@$_POST['no_family_history']) {
			$patient->setNoFamilyHistory();
		} else  {

			if (!$relative = FamilyHistoryRelative::model()->findByPk(@$_POST['relative_id'])) {
				throw new Exception("Unknown relative: ".@$_POST['relative_id']);
			}

			if (!$side = FamilyHistorySide::model()->findByPk(@$_POST['side_id'])) {
				throw new Exception("Unknown side: ".@$_POST['side_id']);
			}

			if (!$condition = FamilyHistoryCondition::model()->findByPk(@$_POST['condition_id'])) {
				throw new Exception("Unknown condition: ".@$_POST['condition_id']);
			}

			if (@$_POST['edit_family_history_id']) {
				if (!$fh = FamilyHistory::model()->findByPk(@$_POST['edit_family_history_id'])) {
					throw new Exception("Family history not found: ".@$_POST['edit_family_history_id']);
				}
				$fh->relative_id = $relative->id;
				if ($relative->is_other) {
					$fh->other_relative = @$_POST['other_relative'];
				}
				$fh->side_id = $side->id;
				$fh->condition_id = $condition->id;
				if ($condition->is_other) {
					$fh->other_condition = @$_POST['other_condition'];
				}
				$fh->comments = @$_POST['comments'];

				if (!$fh->save()) {
					throw new Exception("Unable to save family history: ".print_r($fh->getErrors(),true));
				}
			} else {
				$patient->addFamilyHistory($relative->id,@$_POST['other_relative'],$side->id,$condition->id,@$_POST['other_condition'], @$_POST['comments']);
			}
		}

		$this->redirect(array('patient/view/'.$patient->id));
	}

	public function actionRemovePreviousOperation()
	{
		if (!$patient = Patient::model()->findByPk(@$_GET['patient_id'])) {
			throw new Exception("Patient not found: ".@$_GET['patient_id']);
		}

		if (!$po = PreviousOperation::model()->find('patient_id=? and id=?',array($patient->id,@$_GET['operation_id']))) {
			throw new Exception("Previous operation not found: ".@$_GET['operation_id']);
		}

		if (!$po->delete()) {
			throw new Exception("Failed to remove previous operation: ".print_r($po->getErrors(),true));
		}

		echo 'success';
	}

	public function actionGetPreviousOperation()
	{
		if (!$po = PreviousOperation::model()->findByPk(@$_GET['operation_id'])) {
			throw new Exception("Previous operation not found: ".@$_GET['operation_id']);
		}

		$date = explode('-',$po->date);

		echo json_encode(array(
			'operation' => $po->operation,
			'side_id' => $po->side_id,
			'fuzzy_year' => $date[0],
			'fuzzy_month' => preg_replace('/^0/','',$date[1]),
			'fuzzy_day' => preg_replace('/^0/','',$date[2]),
		));
	}

	public function actionRemoveFamilyHistory()
	{
		if (!$patient = Patient::model()->findByPk(@$_GET['patient_id'])) {
			throw new Exception("Patient not found: ".@$_GET['patient_id']);
		}

		if (!$m = FamilyHistory::model()->find('patient_id=? and id=?',array($patient->id,@$_GET['family_history_id']))) {
			throw new Exception("Family history not found: ".@$_GET['family_history_id']);
		}

		if (!$m->delete()) {
			throw new Exception("Failed to remove family history: ".print_r($m->getErrors(),true));
		}

		echo 'success';
	}

	public function processJsVars()
	{
		if ($this->patient) {
			$this->jsVars['OE_patient_id'] = $this->patient->id;
		}
		$firm = Firm::model()->findByPk(Yii::app()->session['selected_firm_id']);
		$subspecialty_id = $firm->serviceSubspecialtyAssignment ? $firm->serviceSubspecialtyAssignment->subspecialty_id : null;

		$this->jsVars['OE_subspecialty_id'] = $subspecialty_id;

		parent::processJsVars();
	}

	public function actionInstitutionSites()
	{
		if (!$institution = Institution::model()->findByPk(@$_GET['institution_id'])) {
			throw new Exception("Institution not found: ".@$_GET['institution_id']);
		}

		echo json_encode(CHtml::listData($institution->sites,'id','name'));
	}

	public function actionValidateSaveContact()
	{
		if (!$patient = Patient::model()->findByPk(@$_POST['patient_id'])) {
			throw new Exception("Patient not found: ".@$_POST['patient_id']);
		}

		$errors = array();

		if (!$institution = Institution::model()->findByPk(@$_POST['institution_id'])) {
			$errors['institution_id'] = 'Please select an institution';
		}

		if (@$_POST['site_id']) {
			if (!$site = Site::model()->findByPk($_POST['site_id'])) {
				$errors['site_id'] = 'Invalid site';
			}
		}

		if (@$_POST['contact_label_id'] == 'nonspecialty' && !@$_POST['label_id']) {
			$errors['label_id'] = 'Please select a label';
		}

		$contact = new Contact;

		foreach (array('title','first_name','last_name') as $field) {
			if (!@$_POST[$field]) {
				$errors[$field] = $contact->getAttributeLabel($field).' is required';
			}
		}

		echo json_encode($errors);
	}

	public function actionAddContact()
	{
		if (@$_POST['site_id']) {
			if (!$site = Site::model()->findByPk($_POST['site_id'])) {
				throw new Exception("Site not found: ".$_POST['site_id']);
			}
		} else {
			if (!$institution = Institution::model()->findByPk(@$_POST['institution_id'])) {
				throw new Exception("Institution not found: ".@$_POST['institution_id']);
			}
		}
		if (!$patient = Patient::model()->findByPk(@$_POST['patient_id'])) {
			throw new Exception("patient required for contact assignment");
		}

		// Attempt to de-dupe by looking for an existing record that matches the user's input
		$criteria = new CDbCriteria;
		$criteria->compare('lower(title)',strtolower($_POST['title']));
		$criteria->compare('lower(first_name)',strtolower($_POST['first_name']));
		$criteria->compare('lower(last_name)',strtolower($_POST['last_name']));

		if (isset($site)) {
			$criteria->compare('site_id',$site->id);
		} else {
			$criteria->compare('institution_id',$institution->id);
		}

		if ($contact = Contact::model()->with('locations')->find($criteria)) {
			foreach ($contact->locations as $location) {
				$pca = new PatientContactAssignment;
				$pca->patient_id = $patient->id;
				$pca->location_id = $location->id;
				if (!$pca->save()) {
					throw new Exception("Unable to save patient contact assignment: ".print_r($pca->getErrors(),true));
				}

				$this->redirect(array('/patient/view/'.$patient->id));
			}
		}

		$contact = new Contact;
		$contact->attributes = $_POST;

		if (@$_POST['contact_label_id'] == 'nonspecialty') {
			if (!$label = ContactLabel::model()->findByPk(@$_POST['label_id'])) {
				throw new Exception("Contact label not found: ".@$_POST['label_id']);
			}
		} else {
			if (!$label = ContactLabel::model()->find('name=?',array(@$_POST['contact_label_id']))) {
				throw new Exception("Contact label not found: ".@$_POST['contact_label_id']);
			}
		}

		$contact->contact_label_id = $label->id;

		if (!$contact->save()) {
			throw new Exception("Unable to save contact: ".print_r($contact->getErrors(),true));
		}

		$cl = new ContactLocation;
		$cl->contact_id = $contact->id;
		if (isset($site)) {
			$cl->site_id = $site->id;
		} else {
			$cl->institution_id = $institution->id;
		}

		if (!$cl->save()) {
			throw new Exception("Unable to save contact location: ".print_r($cl->getErrors(),true));
		}

		$pca = new PatientContactAssignment;
		$pca->patient_id = $patient->id;
		$pca->location_id = $cl->id;

		if (!$pca->save()) {
			throw new Exception("Unable to save patient contact assignment: ".print_r($pca->getErrors(),true));
		}

		$this->redirect(array('/patient/view/'.$patient->id));
	}

	public function actionGetContactLocation()
	{
		if (!$location = ContactLocation::model()->findByPk(@$_GET['location_id'])) {
			throw new Exception("ContactLocation not found: ".@$_GET['location_id']);
		}

		$data = array();

		if ($location->site) {
			$data['institution_id'] = $location->site->institution_id;
			$data['site_id'] = $location->site_id;
		} else {
			$data['institution_id'] = $location->institution_id;
			$data['site_id'] = null;
		}

		$data['contact_id'] = $location->contact_id;
		$data['name'] = $location->contact->fullName;

		echo json_encode($data);
	}

	public function actionValidateEditContact()
	{
		if (!$patient = Patient::model()->findByPk(@$_POST['patient_id'])) {
			throw new Exception("Patient not found: ".@$_POST['patient_id']);
		}

		if (!$contact = Contact::model()->findByPk(@$_POST['contact_id'])) {
			throw new Exception("Contact not found: ".@$_POST['contact_id']);
		}

		$errors = array();

		if (!@$_POST['institution_id']) {
			$errors['institution_id'] = 'Please select an institution';
		} else {
			if (!$institution = Institution::model()->findByPk(@$_POST['institution_id'])) {
				throw new Exception("Institution not found: ".@$_POST['institution_id']);
			}
		}

		if (@$_POST['site_id']) {
			if (!$site = Site::model()->findByPk(@$_POST['site_id'])) {
				throw new Exception("Site not found: ".@$_POST['site_id']);
			}
		}

		echo json_encode($errors);
	}

	public function actionEditContact()
	{
		if (!$patient = Patient::model()->findByPk(@$_POST['patient_id'])) {
			throw new Exception("Patient not found: ".@$_POST['patient_id']);
		}

		if (!$contact = Contact::model()->findByPk(@$_POST['contact_id'])) {
			throw new Exception("Contact not found: ".@$_POST['contact_id']);
		}

		if (@$_POST['site_id']) {
			if (!$site = Site::model()->findByPk(@$_POST['site_id'])) {
				throw new Exception("Site not found: ".@$_POST['site_id']);
			}
			if (!$cl = ContactLocation::model()->find('contact_id=? and site_id=?',array($contact->id,$site->id))) {
				$cl = new ContactLocation;
				$cl->contact_id = $contact->id;
				$cl->site_id = $site->id;

				if (!$cl->save()) {
					throw new Exception("Unable to save contact location: ".print_r($cl->getErrors(),true));
				}
			}
		} else {
			if (!$institution = Institution::model()->findByPk(@$_POST['institution_id'])) {
				throw new Exception("Institution not found: ".@$_POST['institution_id']);
			}

			if (!$cl = ContactLocation::model()->find('contact_id=? and institution_id=?',array($contact->id,$institution->id))) {
				$cl = new ContactLocation;
				$cl->contact_id = $contact->id;
				$cl->institution_id = $institution->id;

				if (!$cl->save()) {
					throw new Exception("Unable to save contact location: ".print_r($cl->getErrors(),true));
				}
			}
		}

		if (!$pca = PatientContactAssignment::model()->findByPk(@$_POST['pca_id'])) {
			throw new Exception("PCA not found: ".@$_POST['pca_id']);
		}

		$pca->location_id = $cl->id;

		if (!$pca->save()) {
			throw new Exception("Unable to save patient contact assignment: ".print_r($pca->getErrors(),true));
		}

		$this->redirect(array('/patient/view/'.$patient->id));
	}

	public function actionSendSiteMessage()
	{
		$message = Yii::app()->mailer->newMessage();
		$message->setFrom(array($_POST['newsite_from'] => User::model()->findByPk(Yii::app()->user->id)->fullName));
		$message->setTo(array(Yii::app()->params['helpdesk_email']));
		$message->setSubject($_POST['newsite_subject']);
		$message->setBody($_POST['newsite_message']);
		echo Yii::app()->mailer->sendMessage($message) ? '1' : '0';
	}

	public function actionVerifyAddNewEpisode()
	{
		if (!$patient = Patient::model()->findByPk(@$_GET['patient_id'])) {
			throw new Exception("Patient not found: ".@$_GET['patient_id']);
		}

		$firm = Firm::model()->findByPk(Yii::app()->session['selected_firm_id']);

		if ($patient->hasOpenEpisodeOfSubspecialty($firm->getSubspecialtyID())) {
			echo "0";
			return;
		}

		echo "1";
	}

	public function actionAddNewEpisode()
	{
		if (!$patient = Patient::model()->findByPk(@$_POST['patient_id'])) {
			throw new Exception("Patient not found: ".@$_POST['patient_id']);
		}

		if (!empty($_POST['firm_id'])) {
			$firm = Firm::model()->findByPk($_POST['firm_id']);
			if (!$episode = $patient->getOpenEpisodeOfSubspecialty($firm->getSubspecialtyID())) {
				$episode = $patient->addEpisode($firm);
			}

			$this->redirect(array('/patient/episode/'.$episode->id));
		}

		return $this->renderPartial('//patient/add_new_episode',array(
			'patient' => $patient,
			'firm' => Firm::model()->findByPk(Yii::app()->session['selected_firm_id']),
		),false, true);
	}

	public function getEpisodes()
	{
		if ($this->patient && empty($this->episodes)) {
			$this->episodes = array(
				'ordered_episodes'=>$this->patient->getOrderedEpisodes(),
				'legacyepisodes'=>$this->patient->legacyepisodes,
				'supportserviceepisodes'=>$this->patient->supportserviceepisodes,
			);
		}
		return $this->episodes;
	}

	/**
	 * Check create access for the specified event type
	 *
	 * @param Episode $episode
	 * @param EventType $event_type
	 * @return boolean
	 */
	public function checkCreateAccess(Episode $episode, EventType $event_type)
	{
		$oprn = 'OprnCreate' . ($event_type->class_name == 'OphDrPrescription' ? 'Prescription' : 'Event');
		return $this->checkAccess($oprn, $this->firm, $episode, $event_type);
	}
}
