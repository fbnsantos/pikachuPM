<?php
// tabs/chat_embed.php — Secção "Conversa & histórico" embebida no fim da página de
// uma sprint, protótipo ou lead. Uso:
//   include_once __DIR__ . '/chat_embed.php';
//   pk_chat_embed('sprint', $id, $closed);
// O histórico fica sempre disponível; quando a entidade fecha, o canal passa a só-leitura.

if (!function_exists('pk_chat_embed')) {
    /**
     * @param string $type    Canal: sprint|prototype|lead|project|lab
     * @param int    $id      ID da entidade (para lab usar 1)
     * @param bool   $closed  Só leitura
     * @param array  $opts    Opções extra: topics (array de assuntos abertos para lab)
     */
    function pk_chat_embed(string $type, int $id, bool $closed = false, array $opts = []): void
    {
        static $assetsLoaded = false;
        $validTypes = ['sprint', 'prototype', 'lead', 'project', 'lab'];
        if ($id <= 0 || !in_array($type, $validTypes, true)) return;
        $labels = ['sprint' => 'sprint', 'prototype' => 'protótipo', 'lead' => 'lead', 'project' => 'projeto', 'lab' => 'laboratório'];
        $label  = $labels[$type];
        $artigo = in_array($type, ['prototype', 'project', 'lab'], true) ? 'o' : 'a';
        $domId  = 'pkchat-embed-' . $type . '-' . $id;
        $v      = @filemtime(__DIR__ . '/../assets/chat/pkchat.js') ?: 1;
        if (!$assetsLoaded) {
            $assetsLoaded = true;
            echo '<link rel="stylesheet" href="assets/chat/pkchat.css?v=' . $v . '">';
            echo '<script src="assets/chat/pkchat.js?v=' . $v . '"></script>';
        }
        $topics     = $opts['topics'] ?? [];
        $isLab      = $type === 'lab';
        $mountOpts  = json_encode([
            'api'     => 'api/chat.php',
            'mode'    => 'embedded',
            'channel' => ['type' => $type, 'id' => (int)$id],
            'notify'  => false,
            'topics'  => $topics,
            'require_topic' => $isLab,
        ]);
        ?>
        <div class="detail-section mt-4 pkchat-embed-section" id="chat-section">
            <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
                <h5 class="mb-0"><i class="bi bi-chat-dots"></i>
                    <?= $closed ? 'Histórico da conversa' : 'Conversa da equipa' ?>
                    <small class="text-muted fw-normal" style="font-size:.75em">
                        — mensagens e ficheiros partilhados com todos os membros d<?= $artigo ?> <?= $label ?><?= $closed ? ' (arquivado, só leitura)' : '' ?>
                    </small>
                </h5>
                <?php if (!$isLab): ?>
                <a class="btn btn-sm btn-outline-primary" href="?tab=chat&type=<?= $type ?>&id=<?= $id ?>">
                    <i class="bi bi-box-arrow-up-right"></i> Abrir no Chat
                </a>
                <?php endif; ?>
            </div>
            <div id="<?= $domId ?>" style="min-height:40px"></div>
        </div>
        <script>
        (function () {
            var el = document.getElementById(<?= json_encode($domId) ?>);
            var opts = <?= $mountOpts ?>;
            function start() { PKChat.mount(el, opts); }
            if ('IntersectionObserver' in window) {
                var io = new IntersectionObserver(function (entries) {
                    if (entries.some(function (e) { return e.isIntersecting; })) { io.disconnect(); start(); }
                }, { rootMargin: '400px' });
                io.observe(el);
            } else start();
        })();
        </script>
        <?php
    }
}
