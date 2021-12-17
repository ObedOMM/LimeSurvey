<?php if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}
/*
   * LimeSurvey
   * Copyright (C) 2013 The LimeSurvey Project Team / Carsten Schmitz
   * All rights reserved.
   * License: GNU/GPL License v2 or later, see LICENSE.php
   * LimeSurvey is free software. This version may have been modified pursuant
   * to the GNU General Public License, and as distributed it includes or
   * is derivative of works licensed under the GNU General Public License or
   * other free or open source software licenses.
   * See COPYRIGHT.php for copyright notices and details.
   *
     *	Files Purpose: lots of common functions
*/

/**
 * Class QuestionAttribute
 *
 * @property integer $qaid ID Primary key
 * @property integer $qid Question ID
 * @property string $attribute attribute name (max 50 chars)
 * @property string $value Attribute value
 * @property string $language Language code eg:'en'
 *
 * @property Question $question
 * @property Survey $survey
 *
 */
class QuestionAttribute extends LSActiveRecord
{
    protected $xssFilterAttributes = ['value'];

    /**
     * @inheritdoc
     * @return QuestionAttribute
     */
    public static function model($class = __CLASS__)
    {
        /** @var self $model */
        $model = parent::model($class);
        return $model;
    }

    /** @inheritdoc */
    public function tableName()
    {
        return '{{question_attributes}}';
    }

    /** @inheritdoc */
    public function primaryKey()
    {
        return 'qaid';
    }

    /** @inheritdoc */
    public function relations()
    {
        return array(
            /** NB! do not use this relation use $this->question instead @see getQuestion() */
            'qid' => array(self::BELONGS_TO, 'Question', 'qid', 'together' => true),
        );
    }

    /** @inheritdoc */
    public function rules()
    {
        return array(
            array('qid,attribute', 'required'),
            array('value', 'filterXss'),
        );
    }


    /**
     * @param integer $iQuestionID
     * @param string $sAttributeName
     * @param string $sValue
     * @return CDbDataReader
     */
    public function setQuestionAttribute($iQuestionID, $sAttributeName, $sValue)
    {
        $oModel = new self;
        $aResult = $oModel->findAll('attribute=:attributeName and qid=:questionID', array(':attributeName'=>$sAttributeName, ':questionID'=>$iQuestionID));
        if (!empty($aResult)) {
            foreach ($aResult as $questionAttribute) {
                $questionAttribute->value = $sValue;
                $questionAttribute->save();
            }
        } else {
            $oModel = new self;
            $oModel->attribute = $sAttributeName;
            $oModel->value = $sValue;
            $oModel->qid = $iQuestionID;
            $oModel->save();
        }
        return Yii::app()->db->createCommand()
            ->select()
            ->from($this->tableName())
            ->where(array('and', 'qid=:qid'))->bindParam(":qid", $iQuestionID)
            ->order('qaid asc')
            ->query();
    }

    /**
     * Set attributes for multiple questions
     *
     * NOTE: We can't use self::setQuestionAttribute() because it doesn't check for question types first.
     * TODO: the question type check should be done via rules, or via a call to a question method
     * TODO: use an array for POST values, like for a form submit So we could parse it from the controller instead of using $_POST directly here
     *
     * @var integer $iSid                   the sid to update  (only to check permission)
     * @var array $aQidsAndLang           an array containing the list of primary keys for questions ( {qid, lang} )
     * @var array $aAttributesToUpdate    array continaing the list of attributes to update
     * @var array $aValidQuestionTypes    the question types we can update for those attributes
     */
    public function setMultiple($iSid, $aQidsAndLang, $aAttributesToUpdate, $aValidQuestionTypes)
    {
        // Permissions check
        if (Permission::model()->hasSurveyPermission($iSid, 'surveycontent', 'update')) {
            // For each question
            foreach ($aQidsAndLang as $sQidAndLang) {
                $aQidAndLang  = explode(',', $sQidAndLang); // Each $aQidAndLang correspond to a question primary key, which is a pair {qid, lang}.
                $iQid         = $aQidAndLang[0]; // Those pairs are generated by CGridView
                $sLanguage    = $aQidAndLang[1];

                // We need to generate a question object to check for the question type
                // So, we can also force the sid: we don't allow to update questions on different surveys at the same time (permission check is by survey)
                $oQuestion = Question::model()->find('qid=:qid and language=:language and sid=:sid', array(":qid"=>$iQid, ":language"=>$sLanguage, ":sid"=>$iSid));

                // For each attribute
                foreach ($aAttributesToUpdate as $sAttribute) {
                    // TODO: use an array like for a form submit, so we can parse it from the controller instead of using $_POST directly here
                    $sValue = Yii::app()->request->getPost($sAttribute);
                    $questionAttributes = QuestionAttribute::model()->findAll('attribute=:attribute AND qid=:qid', [':attribute' => $sAttribute, ':qid' => $iQid]);

                    // We check if we can update this attribute for this question type
                    // TODO: if (in_array($oQuestion->attributes, $sAttribute))
                    if (in_array($oQuestion->type, $aValidQuestionTypes)) {
                        if (count($questionAttributes) > 0) {
                            // Update
                            foreach ($questionAttributes as $questionAttribute) {
                                $questionAttribute->value = $sValue;
                                $questionAttribute->save();
                            }
                        } else {
                            // Create
                            $oAttribute            = new QuestionAttribute;
                            $oAttribute->qid       = $iQid;
                            $oAttribute->value     = $sValue;
                            $oAttribute->attribute = $sAttribute;
                            $oAttribute->save();
                        }
                    }
                }
            }
        }
    }

    /**
     * Returns Question attribute array name=>value
     *
     * @access public
     * @param int $iQuestionID
     * @param string $sLanguage restrict to this language (@todo : add it in qanda)
     * @return array|boolean
     * @throws CException
     * @todo This function needs to be incorporated in the model because it creates a big number of additional queries. For exmaple the default value merging could be done in AfterFind.
     */
    public function getQuestionAttributes($iQuestionID, $sLanguage = null)
    {

        $iQuestionID = (int) $iQuestionID;
        static $aQuestionAttributesStatic = array(); // TODO : replace by Yii::app()->cache
        // Limit the size of the attribute cache due to memory usage
        if (isset($aQuestionAttributesStatic[$iQuestionID])) {
            return $aQuestionAttributesStatic[$iQuestionID];
        }
        $aQuestionAttributes = array();
        $oQuestion = Question::model()->find("qid=:qid", array('qid'=>$iQuestionID)); // Maybe take parent_qid attribute before this qid attribute

        if ($oQuestion) {
            if ($sLanguage) {
                $aLanguages = array($sLanguage);
            } else {
                $aLanguages = array_merge(array(Survey::model()->findByPk($oQuestion->sid)->language), Survey::model()->findByPk($oQuestion->sid)->additionalLanguages);
            }
            // Get all atribute set for this question
            $sType = $oQuestion->type;

            // For some reason this happened in bug #10684
            if ($sType == null) {
                throw new \CException("Question is corrupt: no type defined for question ".$iQuestionID);
            }

            $aAttributeNames = \LimeSurvey\Helpers\questionHelper::getQuestionAttributesSettings($sType);

            /* Get whole existing attribute for this question in an array*/
            $oAttributeValues = QuestionAttribute::model()->findAll("qid=:qid", array('qid'=>$iQuestionID));

            foreach ($oAttributeValues as $oAttributeValue) {
                if ($oAttributeValue->attribute == 'question_template') {
                    $aAttributeValues['question_template'] = $oAttributeValue->value;
                    $aAttributeNames = Question::getQuestionTemplateAttributes($aAttributeNames, $aAttributeValues, $oQuestion);
                    break;
                }
            }


            $aAttributeValues = array();
            foreach ($oAttributeValues as $oAttributeValue) {
                if ($oAttributeValue->language) {
                    $aAttributeValues[$oAttributeValue->attribute][$oAttributeValue->language] = $oAttributeValue->value;
                } else {
                    /* Don't replace existing language, use '' for null key (and for empty string) */
                    $aAttributeValues[$oAttributeValue->attribute][''] = $oAttributeValue->value;
                }
            }


            // Fill with aQuestionAttributes with default attribute or with aAttributeValues
            // Can not use array_replace due to i18n
            foreach ($aAttributeNames as $aAttribute) {
                $aQuestionAttributes[$aAttribute['name']]['expression'] = isset($aAttribute['expression']) ? $aAttribute['expression'] : 0;

                if ($aAttribute['i18n'] == false) {
                    if (isset($aAttributeValues[$aAttribute['name']][''])) {
                        $aQuestionAttributes[$aAttribute['name']] = $aAttributeValues[$aAttribute['name']][''];
                    } elseif (isset($aAttributeValues[$aAttribute['name']])) {
/* Some survey have language is set for attribute without language (see #11980). This must fix for public survey and not only for admin. */
                        $aQuestionAttributes[$aAttribute['name']] = reset($aAttributeValues[$aAttribute['name']]);
                    } else {
                        $aQuestionAttributes[$aAttribute['name']] = $aAttribute['default'];
                    }
                } else {
                    foreach ($aLanguages as $sLanguage) {
                        if (isset($aAttributeValues[$aAttribute['name']][$sLanguage])) {
                            $aQuestionAttributes[$aAttribute['name']][$sLanguage] = $aAttributeValues[$aAttribute['name']][$sLanguage];
                        } elseif (isset($aAttributeValues[$aAttribute['name']][''])) {
                            $aQuestionAttributes[$aAttribute['name']][$sLanguage] = $aAttributeValues[$aAttribute['name']][''];
                        } else {
                            $aQuestionAttributes[$aAttribute['name']][$sLanguage] = $aAttribute['default'];
                        }
                    }
                }
            }
        } else {
            return false; // return false but don't set $aQuestionAttributesStatic[$iQuestionID]
        }
        $aQuestionAttributesStatic[$iQuestionID] = $aQuestionAttributes;
        return $aQuestionAttributes;
    }

    public static function insertRecords($data)
    {
        $attrib = new self;
        foreach ($data as $k => $v) {
            $attrib->$k = $v;
        }
        return $attrib->save();
    }

    /**
     * @param string $fields
     * @param mixed $condition
     * @param string $orderby
     * @return array
     */
    public function getQuestionsForStatistics($fields, $condition, $orderby = false)
    {
        $command = Yii::app()->db->createCommand()
            ->select($fields)
            ->from($this->tableName())
            ->where($condition);
        if ($orderby != false) {
            $command->order($orderby);
        }
        return $command->queryAll();
    }

    /**
     * @return Question
     */
    public function getQuestion()
    {
        $criteria = new CDbCriteria();
        $criteria->addCondition('qid=:qid');
        $criteria->params = [':qid'=>$this->qid];
        if ($this->language) {
            $criteria->addCondition('language=:language');
            $criteria->params = [':qid'=>$this->qid, ':language'=>$this->language];
        }
        /** @var Question $model */
        $model = Question::model()->find($criteria);
        return $model;
    }

    /**
     * @return Survey
     */
    public function getSurvey()
    {
        return $this->question->survey;
    }

    /**
     * Get default settings for an attribute, return an array of string|null
     * @return (string|bool|null)[]
     */
    public static function getDefaultSettings()
    {
        return array(
            "name" => null,
            "caption" => '',
            "inputtype" => "text",
            "options" => null,
            "category" => gT("Attribute"),
            "default" => '',
            "help" => '',
            "value" => '',
            "sortorder" => 1000,
            "i18n"=> false,
            "readonly" => false,
            "readonly_when_active" => false,
            "expression"=> null,
            "xssfilter" => true,
        );
    }

    /**
     * Apply XSS filter to question attribute value unless 'xssfilter' property is false.
     * @param string $attribute the name of the attribute to be validated.
	 * @param array<mixed> $params additional parameters passed with rule when being executed.
     * @return void
     */
    public function filterXss($attribute, $params)
    {
        $question = Question::model()->find("qid=:qid", ['qid' => $this->qid]);
        if (empty($question)) {
            return;
        }

        $questionAttributeDefinitions = \LimeSurvey\Helpers\questionHelper::getAttributesDefinitions();

        // The value will be filtered unless the attribute definition has the "xssfilter" property set to false
        $shouldFilter = true;
        if (isset($questionAttributeDefinitions[$this->attribute])) {
            $questionAttributeDefinition = $questionAttributeDefinitions[$this->attribute];
            if (array_key_exists("xssfilter", $questionAttributeDefinition) && $questionAttributeDefinition['xssfilter'] == false) {
                $shouldFilter = false;
            }
        }

        if (!$shouldFilter) {
            return;
        }

        // By default, LSYii_Validators only applies an XSS filter. It has other filters but they are not enabled by default.
        $validator = new LSYii_Validators;
        $validator->attributes = [$attribute];
        $validator->validate($this, [$attribute]);
    }
}
