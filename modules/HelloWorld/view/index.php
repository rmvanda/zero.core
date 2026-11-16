<main class="container">
    <h1>HelloWorld Test Module</h1>
    <p>This is a test module demonstrating Zero Framework features.</p>

    <section class="endpoints">
        <h2>Available Endpoints</h2>

        <article class="endpoint-card">
            <h3><a href="/hello-world/say-hello">say-hello</a></h3>
            <p class="description">Basic greeting endpoint. Returns "hello world!" with no arguments.</p>
            <p class="usage">Pass URL arguments to customize the greeting:</p>
            <ul>
                <li><a href="/hello-world/say-hello">/hello-world/say-hello</a> - "hello world!"</li>
                <li><a href="/hello-world/say-hello/Alice">/hello-world/say-hello/Alice</a> - "Hello, Alice!"</li>
                <li><a href="/hello-world/say-hello/Alice/Bob">/hello-world/say-hello/Alice/Bob</a> - "Hello, Alice and Bob!"</li>
                <li><a href="/hello-world/say-hello/Alice/Bob/Carol">/hello-world/say-hello/Alice/Bob/Carol</a> - "Hello, Alice, Bob, and Carol!"</li>
            </ul>
            <p class="note">Supports JSON responses via <code>Accept: application/json</code> header.</p>
        </article>

        <article class="endpoint-card">
            <h3><a href="/hello-world/secure-hello">secure-hello</a></h3>
            <p class="description">Authenticated greeting endpoint. Same functionality as say-hello, but requires user authentication.</p>
            <p class="note">Returns 401 Unauthorized if not logged in.</p>
        </article>

        <article class="endpoint-card">
            <h3><a href="/hello-world/permitted-hello">permitted-hello</a></h3>
            <p class="description">Permission-protected greeting endpoint. Same functionality as secure-hello, but additionally requires the <code>allow.hello.world</code> permission.</p>
            <p class="note">Returns 401 if not logged in, or 403 Forbidden if lacking permission.</p>
        </article>
    </section>
</main>
