<?php /** The red banner while the EDGAR user-agent is empty (the SEC's rule: a business name and a contact it may write to). Data: ua (the user-agent or null) */
if (empty($ua)): ?>
<div class="alert alert-danger" role="alert" id="ua-banner">
    <div class="fw-semibold">EDGAR is waiting for a contact.</div>
    The SEC asks every automated reader to say who it is: a business name and an email it may write to. Until both are in
    <?= has_right('settings.manage') ? hx_link('/admin/settings#settings-edgar', 'Settings', 'alert-link') : 'Settings' ?>, no EDGAR source pulls anything.
</div>
<?php endif; ?>
