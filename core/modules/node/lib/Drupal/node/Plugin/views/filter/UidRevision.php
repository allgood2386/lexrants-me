<?php

/**
 * @file
 * Definition of Drupal\node\Plugin\views\filter\UidRevision.
 */

namespace Drupal\node\Plugin\views\filter;

use Drupal\user\Plugin\views\filter\Name;

/**
 * Filter handler to check for revisions a certain user has created.
 *
 * @ingroup views_filter_handlers
 *
 * @PluginID("node_uid_revision")
 */
class UidRevision extends Name {

  public function query($group_by = FALSE) {
    $this->ensureMyTable();

    $placeholder = $this->placeholder();

    $args = array_values($this->value);

    $this->query->addWhereExpression($this->options['group'], "$this->tableAlias.uid IN($placeholder) OR
      ((SELECT COUNT(DISTINCT vid) FROM {node_revision} nr WHERE nr.revision_uid IN ($placeholder) AND nr.nid = $this->tableAlias.nid) > 0)", array($placeholder => $args),
      $args);
  }

}
