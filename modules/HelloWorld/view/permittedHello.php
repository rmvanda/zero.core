<main class="container">
    <div class="greeting-display permitted">
        <span class="badge auth">Authenticated</span>
        <span class="badge perm">allow.hello.world</span>
        <p class="greeting"><?= $this->data['greeting'] ?></p>
    </div>
    <p class="back-link"><a href="/hello-world">Back to HelloWorld index</a></p>
</main>
