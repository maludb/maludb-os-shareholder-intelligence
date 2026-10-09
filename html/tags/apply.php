<?php
declare(strict_types=1);
/** Action `tag_apply` (log `tag.apply`; tags.apply; undo tag_remove): a tag (a label — new ones are made — or a tag id) on a record the caller may see. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/tags/write.php';
si_handler_begin();
require_right('tags.apply');
$pdo = db();
[$kind, $id, $iss] = record_from_request($pdo, TAG_KINDS);
$tag = (string) req_val('tag');
if ($tag === '' || mb_strlen($tag) > 60) {
    si_refuse_fields(['tag' => 'A tag is 1 to 60 characters.']);
}
[$tid, $label, $added] = si_guard($pdo, static function () use ($pdo, $kind, $id, $tag, $iss): array {
    $pdo->beginTransaction();
    $r = tag_apply($pdo, $kind, $id, $tag, (int) current_member_id());
    if ($r[2]) {
        log_activity($pdo, 'tag.apply', $kind, $id, ['issuer_id' => $iss, 'after' => ['tag_id' => $r[0], 'label' => $r[1]]]);
    }
    $pdo->commit();
    return $r;
});
si_done($added ? 'Tagged it ' . $label : 'It was already tagged ' . $label, $id, si_land(return_path('/')), 'tagChanged', ['tag_id' => $tid]);
