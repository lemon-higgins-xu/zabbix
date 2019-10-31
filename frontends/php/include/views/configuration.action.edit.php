<?php
/*
** Zabbix
** Copyright (C) 2001-2019 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


require_once dirname(__FILE__).'/js/configuration.action.edit.js.php';

$widget = (new CWidget())->setTitle(_('Actions'));

// create form
$actionForm = (new CForm())
	->setId('action.edit')
	->setName('action.edit')
	->setAttribute('aria-labeledby', ZBX_STYLE_PAGE_TITLE)
	->addVar('form', $data['form'])
	->addVar('eventsource', $data['eventsource']);

if ($data['actionid']) {
	$actionForm->addVar('actionid', $data['actionid']);
}

// Action tab.
$action_tab = (new CFormList())
	->addRow(
		(new CLabel(_('Name'), 'name'))->setAsteriskMark(),
		(new CTextBox('name', $data['action']['name']))
			->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
			->setAriaRequired()
			->setAttribute('autofocus', 'autofocus')
	);

// Create condition table.
$condition_table = (new CTable(_('No conditions defined.')))
	->setId('conditionTable')
	->setAttribute('style', 'width: 100%;')
	->setHeader([_('Label'), _('Name'), _('Action')]);

$i = 0;

if ($data['action']['filter']['conditions']) {
	$actionConditionStringValues = actionConditionValueToString([$data['action']], $data['config']);

	foreach ($data['action']['filter']['conditions'] as $cIdx => $condition) {
		if (!isset($condition['conditiontype'])) {
			$condition['conditiontype'] = 0;
		}
		if (!isset($condition['operator'])) {
			$condition['operator'] = 0;
		}
		if (!isset($condition['value'])) {
			$condition['value'] = '';
		}
		if (!array_key_exists('value2', $condition)) {
			$condition['value2'] = '';
		}
		if (!str_in_array($condition['conditiontype'], $data['allowedConditions'])) {
			continue;
		}

		$label = isset($condition['formulaid']) ? $condition['formulaid'] : num2letter($i);

		$labelSpan = (new CSpan($label))
			->addClass('label')
			->setAttribute('data-conditiontype', $condition['conditiontype'])
			->setAttribute('data-formulaid', $label);

		$condition_table->addRow(
			[
				$labelSpan,
				getConditionDescription($condition['conditiontype'], $condition['operator'],
					$actionConditionStringValues[0][$cIdx], $condition['value2']
				),
				(new CCol([
					(new CButton('remove', _('Remove')))
						->onClick('javascript: removeCondition('.$i.');')
						->addClass(ZBX_STYLE_BTN_LINK)
						->removeId(),
					new CVar('conditions['.$i.']', $condition)
				]))->addClass(ZBX_STYLE_NOWRAP)
			],
			null, 'conditions_'.$i
		);

		$i++;
	}
}

$formula = (new CTextBox('formula', $data['action']['filter']['formula']))
	->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
	->setId('formula')
	->setAttribute('placeholder', 'A or (B and C) &hellip;');

$calculationTypeComboBox = new CComboBox('evaltype', $data['action']['filter']['evaltype'],
	'processTypeOfCalculation()',
	[
		CONDITION_EVAL_TYPE_AND_OR => _('And/Or'),
		CONDITION_EVAL_TYPE_AND => _('And'),
		CONDITION_EVAL_TYPE_OR => _('Or'),
		CONDITION_EVAL_TYPE_EXPRESSION => _('Custom expression')
	]
);

$action_tab->addRow(_('Type of calculation'), [
	$calculationTypeComboBox,
	(new CDiv())->addClass(ZBX_STYLE_FORM_INPUT_MARGIN),
	(new CSpan())->setId('conditionLabel'),
	$formula
]);
$action_tab->addRow(_('Conditions'),
	(new CDiv($condition_table))
		->addClass(ZBX_STYLE_TABLE_FORMS_SEPARATOR)
		->setAttribute('style', 'min-width: '.ZBX_TEXTAREA_BIG_WIDTH.'px;')
);

// append new condition to form list
$conditionTypeComboBox = new CComboBox('new_condition[conditiontype]', $data['new_condition']['conditiontype'], 'submit()');
foreach ($data['allowedConditions'] as $key => $condition) {
	$data['allowedConditions'][$key] = [
		'name' => condition_type2str($condition),
		'type' => $condition
	];
}

foreach ($data['allowedConditions'] as $condition) {
	$conditionTypeComboBox->addItem($condition['type'], $condition['name']);
}

$condition_operators_list = get_operators_by_conditiontype($data['new_condition']['conditiontype']);

if (count($condition_operators_list) > 1) {
	$condition_operator = new CComboBox('new_condition[operator]', $data['new_condition']['operator']);

	foreach ($condition_operators_list as $operator) {
		$condition_operator->addItem($operator, condition_operator2str($operator));
	}
}
else {
	$condition_operator = [new CVar('new_condition[operator]', $condition_operators_list[0]),
		condition_operator2str($condition_operators_list[0])
	];
}

$condition2 = null;

switch ($data['new_condition']['conditiontype']) {
	case CONDITION_TYPE_HOST_GROUP:
		$condition = (new CMultiSelect([
			'name' => 'new_condition[value][]',
			'object_name' => 'hostGroup',
			'default_value' => 0,
			'popup' => [
				'parameters' => [
					'srctbl' => 'host_groups',
					'srcfld1' => 'groupid',
					'dstfrm' => $actionForm->getName(),
					'dstfld1' => 'new_condition_value_',
					'editable' => true
				]
			]
		]))->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH);
		break;

	case CONDITION_TYPE_TEMPLATE:
		$condition = (new CMultiSelect([
			'name' => 'new_condition[value][]',
			'object_name' => 'templates',
			'default_value' => 0,
			'popup' => [
				'parameters' => [
					'srctbl' => 'templates',
					'srcfld1' => 'hostid',
					'srcfld2' => 'host',
					'dstfrm' => $actionForm->getName(),
					'dstfld1' => 'new_condition_value_',
					'editable' => true
				]
			]
		]))->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH);
		break;

	case CONDITION_TYPE_HOST:
		$condition = (new CMultiSelect([
			'name' => 'new_condition[value][]',
			'object_name' => 'hosts',
			'default_value' => 0,
			'popup' => [
				'parameters' => [
					'srctbl' => 'hosts',
					'srcfld1' => 'hostid',
					'dstfrm' => $actionForm->getName(),
					'dstfld1' => 'new_condition_value_',
					'editable' => true
				]
			]
		]))->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH);
		break;

	case CONDITION_TYPE_TRIGGER:
		$condition = (new CMultiSelect([
			'name' => 'new_condition[value][]',
			'object_name' => 'triggers',
			'default_value' => 0,
			'popup' => [
				'parameters' => [
					'srctbl' => 'triggers',
					'srcfld1' => 'triggerid',
					'dstfrm' => $actionForm->getName(),
					'dstfld1' => 'new_condition_value_',
					'editable' => true,
					'noempty' => true
				]
			]
		]))->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH);
		break;

	case CONDITION_TYPE_TRIGGER_NAME:
		$condition = (new CTextBox('new_condition[value]', ''))->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH);
		break;

	case CONDITION_TYPE_TIME_PERIOD:
		$condition = (new CTextBox('new_condition[value]', ZBX_DEFAULT_INTERVAL))
			->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH);
		break;

	case CONDITION_TYPE_TRIGGER_SEVERITY:
		$severityNames = [];
		for ($severity = TRIGGER_SEVERITY_NOT_CLASSIFIED; $severity < TRIGGER_SEVERITY_COUNT; $severity++) {
			$severityNames[] = getSeverityName($severity, $data['config']);
		}
		$condition = new CComboBox('new_condition[value]', null, null, $severityNames);
		break;

	case CONDITION_TYPE_DRULE:
		$condition = (new CMultiSelect([
			'name' => 'new_condition[value][]',
			'object_name' => 'drules',
			'default_value' => 0,
			'popup' => [
				'parameters' => [
					'srctbl' => 'drules',
					'srcfld1' => 'druleid',
					'dstfrm' => $actionForm->getName(),
					'dstfld1' => 'new_condition_value_'
				]
			]
		]))->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH);
		break;

	case CONDITION_TYPE_DCHECK:
		$action_tab->addItem(new CVar('new_condition[value]', '0'));
		$condition = [
			(new CTextBox('dcheck', '', true))->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH),
			(new CDiv())->addClass(ZBX_STYLE_FORM_INPUT_MARGIN),
			(new CButton('btn1', _('Select')))
				->addClass(ZBX_STYLE_BTN_GREY)
				->onClick('return PopUp("popup.generic",'.
					CJs::encodeJson([
						'srctbl' => 'dchecks',
						'srcfld1' => 'dcheckid',
						'srcfld2' => 'name',
						'dstfrm' => $actionForm->getName(),
						'dstfld1' => 'new_condition_value',
						'dstfld2' => 'dcheck',
						'writeonly' => '1'
					]).', null, this);'
				)
		];
		break;

	case CONDITION_TYPE_PROXY:
		$condition = (new CMultiSelect([
			'name' => 'new_condition[value]',
			'object_name' => 'proxies',
			'multiple' => false,
			'default_value' => 0,
			'popup' => [
				'parameters' => [
					'srctbl' => 'proxies',
					'srcfld1' => 'proxyid',
					'srcfld2' => 'host',
					'dstfrm' => $actionForm->getName(),
					'dstfld1' => 'new_condition_value'
				]
			]
		]))->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH);
		break;

	case CONDITION_TYPE_DHOST_IP:
		$condition = (new CTextBox('new_condition[value]', '192.168.0.1-127,192.168.2.1'))
			->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH);
		break;

	case CONDITION_TYPE_DSERVICE_TYPE:
		$discoveryCheckTypes = discovery_check_type2str();
		order_result($discoveryCheckTypes);

		$condition = new CComboBox('new_condition[value]', null, null, $discoveryCheckTypes);
		break;

	case CONDITION_TYPE_DSERVICE_PORT:
		$condition = (new CTextBox('new_condition[value]', '0-1023,1024-49151'))->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH);
		break;

	case CONDITION_TYPE_DSTATUS:
		$condition = new CComboBox('new_condition[value]');
		foreach ([DOBJECT_STATUS_UP, DOBJECT_STATUS_DOWN, DOBJECT_STATUS_DISCOVER, DOBJECT_STATUS_LOST] as $stat) {
			$condition->addItem($stat, discovery_object_status2str($stat));
		}
		break;

	case CONDITION_TYPE_DOBJECT:
		$condition = new CComboBox('new_condition[value]');
		foreach ([EVENT_OBJECT_DHOST, EVENT_OBJECT_DSERVICE] as $object) {
			$condition->addItem($object, discovery_object2str($object));
		}
		break;

	case CONDITION_TYPE_DUPTIME:
		$condition = (new CNumericBox('new_condition[value]', 600, 15))->setWidth(ZBX_TEXTAREA_NUMERIC_BIG_WIDTH);
		break;

	case CONDITION_TYPE_DVALUE:
		$condition = (new CTextBox('new_condition[value]', ''))->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH);
		break;

	case CONDITION_TYPE_APPLICATION:
		$condition = (new CTextBox('new_condition[value]', ''))->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH);
		break;

	case CONDITION_TYPE_HOST_NAME:
		$condition = (new CTextBox('new_condition[value]', ''))->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH);
		break;

	case CONDITION_TYPE_EVENT_TYPE:
		$condition = new CComboBox('new_condition[value]', null, null, eventType());
		break;

	case CONDITION_TYPE_HOST_METADATA:
		$condition = (new CTextBox('new_condition[value]', ''))->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH);
		break;

	case CONDITION_TYPE_EVENT_TAG:
		$condition = (new CTextBox('new_condition[value]', ''))
			->setWidth(ZBX_TEXTAREA_TAG_WIDTH)
			->setAttribute('placeholder', _('tag'));
		break;

	case CONDITION_TYPE_EVENT_TAG_VALUE:
		$condition = (new CTextBox('new_condition[value]', ''))
			->setWidth(ZBX_TEXTAREA_TAG_VALUE_WIDTH)
			->setAttribute('placeholder', _('value'));

		$condition2 = (new CTextBox('new_condition[value2]', ''))
			->setWidth(ZBX_TEXTAREA_TAG_WIDTH)
			->setAttribute('placeholder', _('tag'));
		break;

	default:
		$condition = null;
}

$action_tab->addRow(_('New condition'),
	(new CDiv(
		(new CTable())
			->setAttribute('style', 'width: 100%;')
			->addRow(
				new CCol([
					$conditionTypeComboBox,
					(new CDiv())->addClass(ZBX_STYLE_FORM_INPUT_MARGIN),
					$condition2,
					($condition2 === null) ? null : (new CDiv())->addClass(ZBX_STYLE_FORM_INPUT_MARGIN),
					$condition_operator,
					(new CDiv())->addClass(ZBX_STYLE_FORM_INPUT_MARGIN),
					$condition
				])
			)
			->addRow(
				(new CSimpleButton(_('Add')))
					->onClick('javascript: submitFormWithParam("'.$actionForm->getName().'", "add_condition", "1");')
					->addClass(ZBX_STYLE_BTN_LINK)
			)
	))
		->addClass(ZBX_STYLE_TABLE_FORMS_SEPARATOR)
		->setAttribute('style', 'min-width: '.ZBX_TEXTAREA_BIG_WIDTH.'px;')
);

$action_tab->addRow(_('Enabled'),
	(new CCheckBox('status', ACTION_STATUS_ENABLED))->setChecked($data['action']['status'] == ACTION_STATUS_ENABLED)
);

// Operations tab.
$operation_tab = new CFormList();

if ($data['eventsource'] == EVENT_SOURCE_TRIGGERS || $data['eventsource'] == EVENT_SOURCE_INTERNAL) {
	$operation_tab->addRow((new CLabel(_('Default operation step duration'), 'esc_period'))->setAsteriskMark(),
		(new CTextBox('esc_period', $data['action']['esc_period']))
			->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
			->setAriaRequired()
	);
}

$operation_tab
	->addRow(_('Default subject'),
		(new CTextBox('def_shortdata', $data['action']['def_shortdata']))->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
	)
	->addRow(_('Default message'),
		(new CTextArea('def_longdata', $data['action']['def_longdata']))->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
	);

if ($data['eventsource'] == EVENT_SOURCE_TRIGGERS) {
	$operation_tab->addRow(_('Pause operations for suppressed problems'),
		(new CCheckBox('pause_suppressed', ACTION_PAUSE_SUPPRESSED_TRUE))
			->setChecked($data['action']['pause_suppressed'] == ACTION_PAUSE_SUPPRESSED_TRUE)
	);
}

// create operation table
$operations_table = (new CTable())->setAttribute('style', 'width: 100%;');
if ($data['eventsource'] == EVENT_SOURCE_TRIGGERS || $data['eventsource'] == EVENT_SOURCE_INTERNAL) {
	$operations_table->setHeader([_('Steps'), _('Details'), _('Start in'), _('Duration'), _('Action')]);
	$delays = count_operations_delay($data['action']['operations'], $data['action']['esc_period']);
}
else {
	$operations_table->setHeader([_('Details'), _('Action')]);
}

if ($data['action']['operations']) {
	$actionOperationDescriptions = getActionOperationDescriptions([$data['action']], ACTION_OPERATION);

	$default_message = [
		'subject' => $data['action']['def_shortdata'],
		'message' => $data['action']['def_longdata']
	];

	$action_operation_hints = getActionOperationHints($data['action']['operations'], $default_message);

	$simple_interval_parser = new CSimpleIntervalParser();

	foreach ($data['action']['operations'] as $operationid => $operation) {
		if (!str_in_array($operation['operationtype'], $data['allowedOperations'][ACTION_OPERATION])) {
			continue;
		}
		if (!isset($operation['opconditions'])) {
			$operation['opconditions'] = [];
		}
		if (!isset($operation['mediatypeid'])) {
			$operation['mediatypeid'] = 0;
		}

		$details = new CSpan($actionOperationDescriptions[0][$operationid]);

		if (array_key_exists($operationid, $action_operation_hints) && $action_operation_hints[$operationid]) {
			$details->setHint($action_operation_hints[$operationid]);
		}

		$operation_for_popup = $operation + ['id' => $operationid];
		foreach (['opcommand_grp' => 'groupid', 'opcommand_hst' => 'hostid'] as $var => $field) {
			if (array_key_exists($var, $operation_for_popup)) {
				$operation_for_popup[$var] = zbx_objectValues($operation_for_popup[$var], $field);
			}
		}

		if ($data['eventsource'] == EVENT_SOURCE_TRIGGERS || $data['eventsource'] == EVENT_SOURCE_INTERNAL) {
			$esc_steps_txt = null;
			$esc_period_txt = null;
			$esc_delay_txt = null;

			if ($operation['esc_step_from'] < 1) {
				$operation['esc_step_from'] = 1;
			}

			$esc_steps_txt = $operation['esc_step_from'].' - '.$operation['esc_step_to'];

			// display N-N as N
			$esc_steps_txt = ($operation['esc_step_from'] == $operation['esc_step_to'])
				? $operation['esc_step_from']
				: $operation['esc_step_from'].' - '.$operation['esc_step_to'];

			$esc_period_txt = ($simple_interval_parser->parse($operation['esc_period']) == CParser::PARSE_SUCCESS
					&& timeUnitToSeconds($operation['esc_period']) == 0)
				? _('Default')
				: $operation['esc_period'];

			$esc_delay_txt = ($delays[$operation['esc_step_from']] === null)
				? _('Unknown')
				: ($delays[$operation['esc_step_from']] != 0
					? convert_units(['value' => $delays[$operation['esc_step_from']], 'units' => 'uptime'])
					: _('Immediately')
				);

			$operation_row = [
				$esc_steps_txt,
				$details,
				$esc_delay_txt,
				$esc_period_txt,
				(new CCol(
					new CHorList([
						(new CSimpleButton(_('Edit')))
							->onClick('return PopUp("popup.action.operation.edit",'.CJs::encodeJson([
								'type' => ACTION_OPERATION,
								'source' => $data['eventsource'],
								'actionid' => $data['actionid'],
								'operationtype' => $operation['operationtype'],
								'update' => 1,
								'operation' => $operation_for_popup
							]).', null, this);')
							->addClass(ZBX_STYLE_BTN_LINK),
						[
							(new CButton('remove', _('Remove')))
								->onClick('removeOperation('.$operationid.', '.ACTION_OPERATION.');')
								->addClass(ZBX_STYLE_BTN_LINK)
								->removeId(),
							new CVar('operations['.$operationid.']', $operation)
						]
					])
				))->addClass(ZBX_STYLE_NOWRAP)
			];
		}
		else {
			$operation_row = [
				$details,
				(new CCol(
					new CHorList([
						(new CSimpleButton(_('Edit')))
							->onClick('return PopUp("popup.action.operation.edit",'.CJs::encodeJson([
								'type' => ACTION_OPERATION,
								'source' => $data['eventsource'],
								'actionid' => $data['actionid'],
								'operationtype' => $operation['operationtype'],
								'update' => 1,
								'operation' => $operation_for_popup
							]).', null, this);')
							->addClass(ZBX_STYLE_BTN_LINK),
						[
							(new CButton('remove', _('Remove')))
								->onClick('removeOperation('.$operationid.', '.ACTION_OPERATION.');')
								->addClass(ZBX_STYLE_BTN_LINK)
								->removeId(),
							new CVar('operations['.$operationid.']', $operation)
						]
					])
				))->addClass(ZBX_STYLE_NOWRAP)
			];
		}
		$operations_table->addRow($operation_row, null, 'operations_'.$operationid);
	}
}

$operations_table->addRow(
	(new CSimpleButton(_('Add')))
		->onClick('return PopUp("popup.action.operation.edit",'.CJs::encodeJson([
			'type' => ACTION_OPERATION,
			'source' => $data['eventsource'],
		]).', null, this);')
		->addClass(ZBX_STYLE_BTN_LINK)
);

$operation_tab->addRow(_('Operations'),
	(new CDiv($operations_table))
		->addClass(ZBX_STYLE_TABLE_FORMS_SEPARATOR)
		->setAttribute('style', 'min-width: '.ZBX_TEXTAREA_BIG_WIDTH.'px;')
);

// Append tabs to form.
$action_tabs = (new CTabView())
	->addTab('actionTab', _('Action'), $action_tab)
	->addTab('operationTab', _('Operations'), $operation_tab);

$bottom_note = _('At least one operation must exist.');

// Recovery operation tab.
if ($data['eventsource'] == EVENT_SOURCE_TRIGGERS || $data['eventsource'] == EVENT_SOURCE_INTERNAL) {
	$bottom_note = _('At least one operation or recovery operation must exist.');
	$recovery_tab = (new CFormList())
		->addRow(_('Default subject'),
			(new CTextBox('r_shortdata', $data['action']['r_shortdata']))->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
		)
		->addRow(_('Default message'),
			(new CTextArea('r_longdata', $data['action']['r_longdata']))->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
		);

	// Create operation table.
	$operations_table = (new CTable())->setAttribute('style', 'width: 100%;');
	$operations_table->setHeader([_('Details'), _('Action')]);

	if ($data['action']['recovery_operations']) {
		$actionOperationDescriptions = getActionOperationDescriptions([$data['action']], ACTION_RECOVERY_OPERATION);

		$default_message = [
			'subject' => $data['action']['r_shortdata'],
			'message' => $data['action']['r_longdata']
		];

		$action_operation_hints = getActionOperationHints($data['action']['recovery_operations'], $default_message);

		foreach ($data['action']['recovery_operations'] as $operationid => $operation) {
			if (!str_in_array($operation['operationtype'], $data['allowedOperations'][ACTION_RECOVERY_OPERATION])) {
				continue;
			}
			if (!isset($operation['opconditions'])) {
				$operation['opconditions'] = [];
			}
			if (!isset($operation['mediatypeid'])) {
				$operation['mediatypeid'] = 0;
			}

			$details = new CSpan($actionOperationDescriptions[0][$operationid]);

			if (array_key_exists($operationid, $action_operation_hints) && $action_operation_hints[$operationid]) {
				$details->setHint($action_operation_hints[$operationid]);
			}

			$operation_for_popup = $operation + ['id' => $operationid];
			foreach (['opcommand_grp' => 'groupid', 'opcommand_hst' => 'hostid'] as $var => $field) {
				if (array_key_exists($var, $operation_for_popup)) {
					$operation_for_popup[$var] = zbx_objectValues($operation_for_popup[$var], $field);
				}
			}

			$operations_table->addRow([
				$details,
				(new CCol(
					new CHorList([
						(new CSimpleButton(_('Edit')))
							->onClick('return PopUp("popup.action.recovery.edit",'.CJs::encodeJson([
								'type' => ACTION_RECOVERY_OPERATION,
								'source' => $data['eventsource'],
								'actionid' => $data['actionid'],
								'operationtype' => $operation['operationtype'],
								'update' => 1,
								'operation' => $operation_for_popup
							]).', null, this);')
							->addClass(ZBX_STYLE_BTN_LINK),
						[
							(new CButton('remove', _('Remove')))
								->onClick(
									'javascript: removeOperation('.$operationid.', '.ACTION_RECOVERY_OPERATION.');'
								)
								->addClass(ZBX_STYLE_BTN_LINK)
								->removeId(),
							new CVar('recovery_operations['.$operationid.']', $operation)
						]
					])
				))->addClass(ZBX_STYLE_NOWRAP)
			], null, 'recovery_operations_'.$operationid);
		}
	}

	$operations_table->addRow(
		(new CSimpleButton(_('Add')))
			->onClick('return PopUp("popup.action.recovery.edit",'.CJs::encodeJson([
				'type' => ACTION_RECOVERY_OPERATION,
				'source' => $data['eventsource'],
				'actionid' => getRequest('actionid'),
			]).', null, this);')
			->addClass(ZBX_STYLE_BTN_LINK)
	);

	$recovery_tab->addRow(_('Operations'),
		(new CDiv($operations_table))
			->addClass(ZBX_STYLE_TABLE_FORMS_SEPARATOR)
			->setAttribute('style', 'min-width: '.ZBX_TEXTAREA_BIG_WIDTH.'px;')
	);

	$action_tabs->addTab('recoveryOperationTab', _('Recovery operations'), $recovery_tab);
}

// Acknowledge operations
if ($data['eventsource'] == EVENT_SOURCE_TRIGGERS) {
	$bottom_note = _('At least one operation, recovery operation or update operation must exist.');
	$action_formname = $actionForm->getName();

	$acknowledge_tab = (new CFormList())
		->addRow(_('Default subject'),
			(new CTextBox('ack_shortdata', $data['action']['ack_shortdata']))->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
		)
		->addRow(_('Default message'),
			(new CTextArea('ack_longdata', $data['action']['ack_longdata']))->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
		);

	$operations_table = (new CTable())->setAttribute('style', 'width: 100%;');
	$operations_table->setHeader([_('Details'), _('Action')]);

	if ($data['action']['ack_operations']) {
		$operation_descriptions = getActionOperationDescriptions([$data['action']], ACTION_ACKNOWLEDGE_OPERATION);

		$default_message = [
			'subject' => $data['action']['ack_shortdata'],
			'message' => $data['action']['ack_longdata']
		];

		$operation_hints = getActionOperationHints($data['action']['ack_operations'], $default_message);

		foreach ($data['action']['ack_operations'] as $operationid => $operation) {
			if (!str_in_array($operation['operationtype'], $data['allowedOperations'][ACTION_ACKNOWLEDGE_OPERATION])) {
				continue;
			}
			$operation += [
				'opconditions'	=> [],
				'mediatypeid'	=> 0
			];

			$details = new CSpan($operation_descriptions[0][$operationid]);

			if (array_key_exists($operationid, $operation_hints) && $operation_hints[$operationid]) {
				$details->setHint($operation_hints[$operationid]);
			}

			$operation_for_popup = $operation + ['id' => $operationid];
			foreach (['opcommand_grp' => 'groupid', 'opcommand_hst' => 'hostid'] as $var => $field) {
				if (array_key_exists($var, $operation_for_popup)) {
					$operation_for_popup[$var] = zbx_objectValues($operation_for_popup[$var], $field);
				}
			}

			$operations_table->addRow([
				$details,
				(new CCol(
					new CHorList([
						(new CSimpleButton(_('Edit')))
							->onClick('return PopUp("popup.action.acknowledge.edit",'.CJs::encodeJson([
								'type' => ACTION_ACKNOWLEDGE_OPERATION,
								'source' => $data['eventsource'],
								'actionid' => $data['actionid'],
								'operationtype' => $operation['operationtype'],
								'update' => 1,
								'operation' => $operation_for_popup
							]).', null, this);')
							->addClass(ZBX_STYLE_BTN_LINK),
						[
							(new CButton('remove', _('Remove')))
								->onClick('javascript: removeOperation('.$operationid.', '.ACTION_ACKNOWLEDGE_OPERATION.
									');'
								)
								->addClass(ZBX_STYLE_BTN_LINK)
								->removeId(),
							new CVar('ack_operations['.$operationid.']', $operation)
						]
					])
				))->addClass(ZBX_STYLE_NOWRAP)
			], null, 'ack_operations_'.$operationid);
		}
	}

	$operations_table->addRow(
		(new CSimpleButton(_('Add')))
			->onClick('return PopUp("popup.action.acknowledge.edit",'.CJs::encodeJson([
				'type' => ACTION_ACKNOWLEDGE_OPERATION,
				'source' => $data['eventsource'],
				'actionid' => getRequest('actionid'),
			]).', null, this);')
			->addClass(ZBX_STYLE_BTN_LINK)
	);

	$acknowledge_tab->addRow(_('Operations'),
		(new CDiv($operations_table))
			->addClass(ZBX_STYLE_TABLE_FORMS_SEPARATOR)
			->addStyle('min-width: '.ZBX_TEXTAREA_BIG_WIDTH.'px;')
	);

	$action_tabs->addTab('acknowledgeTab', _('Update operations'), $acknowledge_tab);
}

if (!hasRequest('form_refresh')) {
	$action_tabs->setSelected(0);
}

// Append buttons to form.
$others = [];
if ($data['actionid']) {
	$form_buttons = [
		new CSubmit('update', _('Update')), [
			new CButton('clone', _('Clone')),
			new CButtonDelete(
				_('Delete current action?'),
				url_param('form').url_param('eventsource').url_param('actionid')
			),
			new CButtonCancel(url_param('actiontype'))
		]
	];
}
else {
	$form_buttons = [
		new CSubmit('add', _('Add')),
		[new CButtonCancel(url_param('actiontype'))]
	];
}

$action_tabs->setFooter([
	(new CList())
		->addClass(ZBX_STYLE_TABLE_FORMS)
		->addItem([
			new CDiv(''),
			(new CDiv((new CLabel($bottom_note))->setAsteriskMark()))
				->addClass(ZBX_STYLE_TABLE_FORMS_TD_RIGHT)
		]),
	makeFormFooter($form_buttons[0], $form_buttons[1])
]);
$actionForm->addItem($action_tabs);

// Append form to widget.
$widget->addItem($actionForm);

return $widget;
