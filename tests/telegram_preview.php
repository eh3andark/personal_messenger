<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/AssistantBridge.php';

$rows = '<div class="tgme_channel_info_header_title">Test &amp; Channel</div>';
for ($id = 1; $id <= 25; $id++) {
    $text = $id === 3 ? '<script>alert(1)</script>Safe <b>post</b>' : 'Visible ' . $id;
    $rows .= '<div class="tgme_widget_message" data-post="TestChannel/' . $id . '"><div class="tgme_widget_message_text">' . $text . '</div><time datetime="2026-10-26T06:00:00+00:00"></time></div>';
}
$rows .= '<div class="tgme_widget_message" data-post="OtherChannel/26"><div class="tgme_widget_message_text">MUST NOT READ</div></div>';
$result = AssistantBridge::parseTelegramPublicPreview($rows, 'TestChannel');
if (!$result['ok'] || count($result['posts']) !== 20 ||
    $result['posts'][0]['url'] !== 'https://t.me/testchannel/6' ||
    $result['posts'][19]['url'] !== 'https://t.me/testchannel/25' ||
    strpos(json_encode($result, JSON_UNESCAPED_UNICODE), 'MUST NOT READ') !== false) {
    throw new RuntimeException('Telegram public preview fixture failed: ' . json_encode($result));
}
if (AssistantBridge::parseTelegramPublicPreview($rows, 'invalid/channel')['ok']) {
    throw new RuntimeException('Invalid channel ID accepted.');
}
$mediaHtml = '<div class="tgme_widget_message" data-post="MediaChannel/1"><a class="tgme_widget_message_photo_wrap" style="background-image:url(\'https://cdn1.telesco.pe/file/picture.jpg\')"></a></div>';
$mediaHtml .= '<div class="tgme_widget_message" data-post="MediaChannel/2"><video src="https://cdn2.telesco.pe/file/clip.mp4?token=abc"></video></div>';
$mediaHtml .= '<div class="tgme_widget_message" data-post="MediaChannel/3"><a class="tgme_widget_message_document_wrap" href="https://t.me/MediaChannel/3">document</a></div>';
$mediaHtml .= '<div class="tgme_widget_message" data-post="MediaChannel/4"><a class="tgme_widget_message_photo_wrap" style="background-image:url(\'http://127.0.0.1/secret\')"></a></div>';
$mediaHtml .= '<div class="tgme_widget_message" data-post="MediaChannel/5"><audio src="https://cdn3.telesco.pe/file/voice.ogg"></audio></div>';
$media = AssistantBridge::parseTelegramPublicPreview($mediaHtml, 'MediaChannel')['posts'];
if (count($media) !== 5 || $media[0]['media_type'] !== 'photo' || $media[0]['media_url'] === '' ||
    $media[1]['media_type'] !== 'video' || $media[1]['media_url'] === '' ||
    $media[2]['media_type'] !== 'file' || $media[2]['media_url'] !== '' ||
    $media[3]['media_type'] !== 'photo' || $media[3]['media_url'] !== '' ||
    $media[4]['media_type'] !== 'audio' || $media[4]['media_url'] === '') {
    throw new RuntimeException('Telegram media availability/type parsing failed: ' . json_encode($media));
}
foreach (['http://cdn1.telesco.pe/file/a.jpg','https://cdn1.telesco.pe.evil.test/file/x.jpg',
          'https://cdn1.telesco.pe:443/file/x.jpg','https://cdn1.telesco.pe/private',
          'https://cdn1.telesco.pe/file/x.jpg?token=ok&x=evil','https://127.0.0.1/file/a.png'] as $badUrl) {
    if (AssistantBridge::isAllowedTelegramCdn($badUrl)) throw new RuntimeException('Unsafe CDN URL accepted: ' . $badUrl);
}
echo "PASS: bounded 20-post public Telegram parser, categorized photos/video/audio/docs, unsafe media URLs blocked\n";
