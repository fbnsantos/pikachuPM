<?php
// tabs/chat.php — Chat estilo Teams: canais = sprints, protótipos e leads do utilizador.
// UI em assets/chat/pkchat.js; backend em api/chat.php (sessão PHP).
if (!isset($_SESSION['username'])) {
    header('Location: login.php');
    exit;
}
$chatType = $_GET['type'] ?? '';
$chatId   = (int)($_GET['id'] ?? 0);
$chatInit = in_array($chatType, ['sprint', 'prototype', 'lead'], true) && $chatId > 0
    ? ['type' => $chatType, 'id' => $chatId] : null;
$chatAssetV = @filemtime(__DIR__ . '/../assets/chat/pkchat.js') ?: 1;
?>
<link rel="stylesheet" href="assets/chat/pkchat.css?v=<?= $chatAssetV ?>">
<style>
    #pkchat-root { height: calc(100vh - 230px); min-height: 520px; }
    @media (max-width: 720px) { #pkchat-root { height: calc(100vh - 160px); } }
</style>
<div id="pkchat-root"></div>
<script src="assets/chat/pkchat.js?v=<?= $chatAssetV ?>"></script>
<script>
(function () {
    const baseTitle = document.title;
    window.pkChatApp = PKChat.mount(document.getElementById('pkchat-root'), {
        api: 'api/chat.php',
        mode: 'full',
        linkBase: 'index.php',
        channel: <?= json_encode($chatInit) ?>,
        onUnread: function (total) {
            document.title = (total ? '(' + total + ') ' : '') + baseTitle;
            if (window.pkChatSetNavBadge) window.pkChatSetNavBadge(total);
        }
    });
})();
</script>
