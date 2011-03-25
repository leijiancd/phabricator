<?php

/*
 * Copyright 2011 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

class HeraldTranscriptController extends HeraldController {

  const FILTER_AFFECTED = 'affected';
  const FILTER_OWNED    = 'owned';
  const FILTER_ALL      = 'all';

  private $id;
  private $filter;
  private $handles;

  public function willProcessRequest(array $data) {
    $this->id = $data['id'];
    $map = $this->getFilterMap();
    $this->filter = idx($data, 'filter');
    if (empty($map[$this->filter])) {
      $this->filter = self::FILTER_AFFECTED;
    }
  }

  public function processRequest() {

    $xscript = id(new HeraldTranscript())->load($this->id);
    if (!$xscript) {
      throw new Exception('Uknown transcript!');
    }

    $field_names = HeraldFieldConfig::getFieldMap();
    $condition_names = HeraldConditionConfig::getConditionMap();
    $action_names = HeraldActionConfig::getActionMap();

    require_celerity_resource('herald-test-css');

    $filter = $this->getFilterPHIDs();
    $this->filterTranscript($xscript, $filter);
    $phids = array_merge($filter, $this->getTranscriptPHIDs($xscript));
    $phids = array_unique($phids);
    $phids = array_filter($phids);

    $handles = id(new PhabricatorObjectHandleData($phids))
      ->loadHandles();
    $this->handles = $handles;

    $object_xscript = $xscript->getObjectTranscript();

    $nav = $this->buildSideNav();

    $apply_xscript_panel = $this->buildApplyTranscriptPanel(
      $xscript);
    $nav->appendChild($apply_xscript_panel);

    $action_xscript_panel = $this->buildActionTranscriptPanel(
      $xscript);
    $nav->appendChild($action_xscript_panel);

    $object_xscript_panel = $this->buildObjectTranscriptPanel(
      $xscript);
    $nav->appendChild($object_xscript_panel);

/*


    $notice = null;
    if ($xscript->getDryRun()) {
      $notice =
        <tools:notice title="Dry Run">
          This was a dry run to test Herald rules, no actions were executed.
        </tools:notice>;
    }

    if (!$object_xscript) {
      $notice =
        <x:frag>
          <tools:notice title="Old Transcript">
            Details of this transcript have been discarded. Full transcripts
            are retained for 30 days.
          </tools:notice>
          {$notice}
        </x:frag>;
    }


    return
      <herald:standard-page title="Transcript">
        <div style="padding: 1em;">
          <tools:side-nav items={$this->renderNavItems()}>
            {$notice}
            {$apply_xscript_markup}
            {$rule_table}
            {$object_xscript_table}
          </tools:side-nav>
        </div>
      </herald:standard-page>;
*/

    return $this->buildStandardPageResponse(
      $nav,
      array(
        'title' => 'Transcript',
      ));
  }

  protected function renderConditionTestValue($condition, $handles) {
    $value = $condition->getTestValue();
    if (!is_scalar($value) && $value !== null) {
      foreach ($value as $key => $phid) {
        $handle = idx($handles, $phid);
        if ($handle) {
          $value[$key] = $handle->getName();
        } else {
          // This shouldn't ever really happen as we are supposed to have
          // grabbed handles for everything, but be super liberal in what
          // we accept here since we expect all sorts of weird issues as we
          // version the system.
          $value[$key] = 'Unknown Object #'.$phid;
        }
      }
      sort($value);
      $value = implode(', ', $value);
    }

    return
      '<span class="condition-test-value">'.
        phutil_escape_html($value).
      '</span>';
  }

  private function buildSideNav() {
    $nav = new AphrontSideNavView();

    $items = array();
    $filters = $this->getFilterMap();
    foreach ($filters as $key => $name) {
      $nav->addNavItem(
        phutil_render_tag(
          'a',
          array(
            'href' => '/herald/transcript/'.$this->id.'/'.$key.'/',
            'class' =>
              ($key == $this->filter)
                ? 'aphront-side-nav-selected'
                : null,
          ),
          phutil_escape_html($name)));
    }

    return $nav;
  }

  protected function getFilterMap() {
    return array(
      self::FILTER_AFFECTED => 'Rules that Affected Me',
      self::FILTER_OWNED    => 'Rules I Own',
      self::FILTER_ALL      => 'All Rules',
    );
  }


  protected function getFilterPHIDs() {
    return array($this->getRequest()->getUser()->getPHID());

/* TODO
    $viewer_id = $this->getRequest()->getUser()->getPHID();

    $fbids = array();
    if ($this->filter == self::FILTER_AFFECTED) {
      $fbids[] = $viewer_id;
      require_module_lazy('intern/subscriptions');
      $datastore = new SubscriberDatabaseStore();
      $lists = $datastore->getUserMailmanLists($viewer_id);
      foreach ($lists as $list) {
        $fbids[] = $list;
      }
    }
    return $fbids;
*/
  }

  protected function getTranscriptPHIDs($xscript) {
    $phids = array();

    $object_xscript = $xscript->getObjectTranscript();
    if (!$object_xscript) {
      return array();
    }

    $phids[] = $object_xscript->getPHID();

    foreach ($xscript->getApplyTranscripts() as $apply_xscript) {
      // TODO: This is total hacks. Add another amazing layer of abstraction.
      $target = (array)$apply_xscript->getTarget();
      foreach ($target as $phid) {
        if ($phid) {
          $phids[] = $phid;
        }
      }
    }

    foreach ($xscript->getRuleTranscripts() as $rule_xscript) {
      $phids[] = $rule_xscript->getRuleOwner();
    }

    $condition_xscripts = $xscript->getConditionTranscripts();
    if ($condition_xscripts) {
      $condition_xscripts = call_user_func_array(
        'array_merge',
        $condition_xscripts);
    }
    foreach ($condition_xscripts as $condition_xscript) {
      $value = $condition_xscript->getTestValue();
      // TODO: Also total hacks.
      if (is_array($value)) {
        foreach ($value as $phid) {
          if ($phid) { // TODO: Probably need to make sure this "looks like" a
                       // PHID or decrease the level of hacks here; this used
                       // to be an is_numeric() check in Facebook land.
            $phids[] = $phid;
          }
        }
      }
    }

    return $phids;
  }

  protected function filterTranscript($xscript, $filter_phids) {
    $filter_owned = ($this->filter == self::FILTER_OWNED);
    $filter_affected = ($this->filter == self::FILTER_AFFECTED);

    if (!$filter_owned && !$filter_affected) {
      // No filtering to be done.
      return;
    }

    if (!$xscript->getObjectTranscript()) {
      return;
    }

    $user_phid = $this->getRequest()->getUser()->getPHID();

    $keep_apply_xscripts = array();
    $keep_rule_xscripts  = array();

    $filter_phids = array_fill_keys($filter_phids, true);

    $rule_xscripts = $xscript->getRuleTranscripts();
    foreach ($xscript->getApplyTranscripts() as $id => $apply_xscript) {
      $rule_id = $apply_xscript->getRuleID();
      if ($filter_owned) {
        if (!$rule_xscripts[$rule_id]) {
          // No associated rule so you can't own this effect.
          continue;
        }
        if ($rule_xscripts[$rule_id]->getRuleOwner() != $user_phid) {
          continue;
        }
      } else if ($filter_affected) {
        $targets = (array)$apply_xscript->getTarget();
        if (!array_select_keys($filter_phids, $targets)) {
          continue;
        }
      }
      $keep_apply_xscripts[$id] = true;
      if ($rule_id) {
        $keep_rule_xscripts[$rule_id] = true;
      }
    }

    foreach ($rule_xscripts as $rule_id => $rule_xscript) {
      if ($filter_owned && $rule_xscript->getRuleOwner() == $user_phid) {
        $keep_rule_xscripts[$rule_id] = true;
      }
    }

    $xscript->setRuleTranscripts(
      array_intersect_key(
        $xscript->getRuleTranscripts(),
        $keep_rule_xscripts));

    $xscript->setApplyTranscripts(
      array_intersect_key(
        $xscript->getApplyTranscripts(),
        $keep_apply_xscripts));

    $xscript->setConditionTranscripts(
      array_intersect_key(
        $xscript->getConditionTranscripts(),
        $keep_rule_xscripts));
  }

  private function buildApplyTranscriptPanel($xscript) {
    $handles = $this->handles;

    $action_names = HeraldActionConfig::getActionMap();

    $rows = array();
    foreach ($xscript->getApplyTranscripts() as $apply_xscript) {
      // TODO: Hacks, this is an approximate guess at the target type.
      $target = (array)$apply_xscript->getTarget();
      if (!$target) {
        if ($apply_xscript->getAction() == HeraldActionConfig::ACTION_NOTHING) {
          $target = '';
        } else {
          $target = '<empty>';
        }
      } else {
        foreach ($target as $k => $phid) {
          $target[$k] = $handles[$phid]->getName();
        }
        $target = implode("\n", $target);
      }
      $target = phutil_escape_html($target);

      if ($apply_xscript->getApplied()) {
        $outcome = '<span class="outcome-success">SUCCESS</span>';
      } else {
        $outcome = '<span class="outcome-failure">FAILURE</span>';
      }
      $outcome .= ' '.phutil_escape_html($apply_xscript->getAppliedReason());

      $rows[] = array(
        phutil_escape_html($action_names[$apply_xscript->getAction()]),
        $target,
        '<strong>Taken because:</strong> '.
        phutil_escape_html($apply_xscript->getReason()).
        '<br />'.
        '<strong>Outcome:</strong> '.$outcome,
      );
    }

    $table = new AphrontTableView($rows);
    $table->setNoDataString('No actions were taken.');
    $table->setHeaders(
      array(
        'Action',
        'Target',
        'Details',
      ));
    $table->setColumnClasses(
      array(
        '',
        '',
        'wide',
      ));

    $panel = new AphrontPanelView();
    $panel->setHeader('Actions Taken');
    $panel->appendChild($table);

    return $panel;
  }

  private function buildActionTranscriptPanel($xscript) {
    $action_xscript = mgroup($xscript->getApplyTranscripts(), 'getRuleID');

    $field_names = HeraldFieldConfig::getFieldMap();
    $condition_names = HeraldConditionConfig::getConditionMap();
    $action_names = HeraldActionConfig::getActionMap();

    $handles = $this->handles;

    $rule_markup = array();
    foreach ($xscript->getRuleTranscripts() as $rule_id => $rule) {
      $cond_markup = array();
      foreach ($xscript->getConditionTranscriptsForRule($rule_id) as $cond) {
        if ($cond->getNote()) {
          $note =
            '<div class="herald-condition-note">'.
              phutil_escape_html($cond->getNote()).
            '</div>';
        } else {
          $note = null;
        }

        if ($cond->getResult()) {
          $result =
            '<span class="herald-outcome condition-pass">'.
              "\xE2\x9C\x93".
            '</span>';
        } else {
          $result =
            '<span class="herald-outcome condition-fail">'.
              "\xE2\x9C\x98".
            '</span>';
        }

        $cond_markup[] =
          '<li>'.
            $result.' Condition: '.
            phutil_escape_html($field_names[$cond->getFieldName()]).
            ' '.
            phutil_escape_html($condition_names[$cond->getCondition()]).
            ' '.
            $this->renderConditionTestValue($cond, $handles).
            $note.
          '</li>';
      }

      if ($rule->getResult()) {
        $result = '<span class="herald-outcome rule-pass">PASS</span>';
        $class = 'herald-rule-pass';
      } else {
        $result = '<span class="herald-outcome rule-fail">FAIL</span>';
        $class = 'herald-rule-fail';
      }

      $cond_markup[] =
        '<li>'.$result.' '.phutil_escape_html($rule->getReason()).'</li>';

/*
      if ($rule->getResult()) {
        $actions = idx($action_xscript, $rule_id, array());
        if ($actions) {
          $cond_markup[] = <li><div class="action-header">Actions</div></li>;
          foreach ($actions as $action) {

            $target = $action->getTarget();
            if ($target) {
              foreach ((array)$target as $k => $phid) {
                $target[$k] = $handles[$phid]->getName();
              }
              $target = <strong>: {implode(', ', $target)}</strong>;
            }

            $cond_markup[] =
              <li>
                {$action_names[$action->getAction()]}
                {$target}
              </li>;
          }
        }
      }
*/
      $user_phid = $this->getRequest()->getUser()->getPHID();

      $name = $rule->getRuleName();
      if ($rule->getRuleOwner() == $user_phid) {
//        $name = <a href={"/herald/rule/".$rule->getRuleID()."/"}>{$name}</a>;
      }

      $rule_markup[] =
        phutil_render_tag(
          'li',
          array(
            'class' => $class,
          ),
          '<div class="rule-name">'.
            '<strong>'.phutil_escape_html($name).'</strong> '.
            phutil_escape_html($handles[$rule->getRuleOwner()]->getName()).
          '</div>'.
          '<ul>'.implode("\n", $cond_markup).'</ul>');
    }

    $panel = new AphrontPanelView();
    $panel->setHeader('Rule Details');
    $panel->appendChild(
      '<ul class="herald-explain-list">'.
        implode("\n", $rule_markup).
      '</ul>');

    return $panel;
  }

  private function buildObjectTranscriptPanel($xscript) {

    $field_names = HeraldFieldConfig::getFieldMap();

    $object_xscript = $xscript->getObjectTranscript();

    $data = array();
    if ($object_xscript) {
      $data += array(
        'Object Name' => $object_xscript->getName(),
        'Object Type' => $object_xscript->getType(),
        'Object PHID' => $object_xscript->getPHID(),
      );
    }

    $data += $xscript->getMetadataMap();

    if ($object_xscript) {
      foreach ($object_xscript->getFields() as $field => $value) {
        $field = idx($field_names, $field, '['.$field.'?]');
        $data['Field: '.$field] = $value;
      }
    }

    $rows = array();
    foreach ($data as $name => $value) {
      if (!is_scalar($value) && !is_null($value)) {
        $value = implode("\n", $value);
      }

      if (strlen($value) > 256) {
        $value = phutil_render_tag(
          'textarea',
          array(
            'class' => 'herald-field-value-transcript',
          ),
          phutil_escape_html($value));
      } else {
        $value = phutil_escape_html($value);
      }

      $rows[] = array(
        phutil_escape_html($name),
        $value,
      );
    }

    $table = new AphrontTableView($rows);
    $table->setColumnClasses(
      array(
        'header',
        'wide',
      ));

    $panel = new AphrontPanelView();
    $panel->setHeader('Object Transcript');
    $panel->appendChild($table);

    return $panel;
  }


}
