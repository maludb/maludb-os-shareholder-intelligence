<?php /** The admin's tab bar: one link per screen the person's rights open. Data: active (sources, classes, templates, settings, tokens, purge, agents) */
$tabs = [['sources', '/admin/sources', 'Sources', 'sources.manage'], ['classes', '/admin/participant-classes', 'Classes', 'issuers.manage'], ['templates', '/admin/templates', 'Templates', 'settings.manage'],
         ['settings', '/admin/settings', 'Settings', 'settings.manage'], ['tokens', '/admin/tokens', 'Keys and tokens', 'tokens.manage'], ['purge', '/admin/purge', 'Purge', 'records.purge'],
         ['agents', '/admin/agents', 'Agents', 'agents.settings']];
?>
<ul class="nav nav-pills gap-1 mb-3 flex-nowrap overflow-auto" id="admin-tabs" aria-label="Administration">
<?php foreach ($tabs as [$key, $url, $label, $right]): if (!has_right($right)) { continue; } ?>
    <li class="nav-item"><?= hx_link($url, e($label), 'nav-link btn-touch d-flex align-items-center text-nowrap' . ($active === $key ? ' active' : ''), 'id="admin-tab-' . e($key) . '"' . ($active === $key ? ' aria-current="page"' : '')) ?></li>
<?php endforeach; ?>
</ul>
