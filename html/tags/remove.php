<?php
declare(strict_types=1);
/** Action `tag_remove` (log `tag.remove`; tags.apply; undo tag_apply): take a tag (label or id) off a record the caller may see. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/tags/write.php';
si_handler_begin();
require_right('tags.apply');
$pdo = db();
[$kind, $id, $iss] = record_from_request($pdo, TAG_KINDS);
$tag = (string) req_val('tag');
if ($tag === '') {
    si_refuse_fields(['tag' => 'Say which tag to take off.']);
}
$pdo->beginTransaction();
[$tid, $removed] = tag_remove($pdo, $kind, $id, $tag);
if ($removed) {
    log_activity($pdo, 'tag.remove', $kind, $id, ['issuer_id' => $iss, 'before' => ['tag_id' => $tid]]);
}
$pdo->commit();
si_done($removed ? 'Took the tag off' : 'It did not carry that tag', $id, si_land(return_path('/')), 'tagChanged', ['tag_id' => $tid]);
