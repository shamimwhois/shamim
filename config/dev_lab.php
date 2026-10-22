<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Listing Sizes
    |--------------------------------------------------------------------------
    |
    | Rows per page for the two lists that paginate: the public gallery and the
    | account's own inventory. Separate numbers for the same reason the Ideas
    | config keeps them separate. A visitor comparing somebody else's snippets
    | wants a few long code blocks; an account hunting for the one it saved last
    | week wants to scan titles.
    |
    */

    'per_page' => [
        'public' => (int) env('DEV_LAB_PER_PAGE', 12),
        'workspace' => (int) env('DEV_LAB_PER_PAGE_WORKSPACE', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Workspace Bands
    |--------------------------------------------------------------------------
    |
    | The dashboard shows the workspaces most recently touched, and nothing else.
    | A workspace is a scratch buffer, so an inventory of old ones is not a list
    | anybody reads; the shelf is the door back into the editor.
    |
    */

    'dashboard' => [
        'recent' => (int) env('DEV_LAB_DASHBOARD_RECENT', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Code Ceilings
    |--------------------------------------------------------------------------
    |
    | The largest single pane the editor will store, in bytes. The columns are
    | `longText`, which could hold far more than anybody can read in a browser
    | tab, so the limit is a product decision rather than a storage one: past
    | this size the work is a file, and a file belongs in a repository.
    |
    | The three panes are limited individually. A demo page with a long CSS
    | animation should not be refused because somebody pasted a large HTML table
    | into the other pane.
    |
    */

    'max_code_bytes' => (int) env('DEV_LAB_MAX_CODE_BYTES', 30000),

    /*
    |--------------------------------------------------------------------------
    | Daily Run Limit
    |--------------------------------------------------------------------------
    |
    | How many times one account may press Run in a day. The code executes in the
    | visitor's own browser, so the limit is not a defence against a server
    | burning CPU; it is the answer to the tab that loops, the browser that
    | freezes, and the support request that follows. It is enforced by
    | {@see \App\Actions\DevLab\RecordLabExecution}, which refuses the write, and
    | the button is disabled on the screen that reads the remaining count.
    |
    */

    'daily_run_limit' => (int) env('DEV_LAB_DAILY_RUN_LIMIT', 100),

    /*
    |--------------------------------------------------------------------------
    | Console Output Ceiling
    |--------------------------------------------------------------------------
    |
    | How many characters of one logged line the panel keeps. A loop that prints
    | a megabyte of text is the most likely way this module is misused, and the
    | cap is applied at both ends: the shim inside the frame truncates before it
    | posts, and the parent truncates again before it renders.
    |
    */

    'max_log_line_chars' => (int) env('DEV_LAB_MAX_LOG_LINE_CHARS', 4000),

    /*
    |--------------------------------------------------------------------------
    | Landing Showcase
    |--------------------------------------------------------------------------
    |
    | The three panes of the built-in example the public landing offers to run.
    | They live in configuration rather than in the template because the frame is
    | composed by JavaScript from a JSON literal, and a code sample written inside
    | an `x-data` attribute has to survive PHP escaping, Blade escaping and
    | attribute escaping before it reaches the browser. Here it is just a string.
    |
    | The example is the site's own, not a visitor's, and it is still only run when
    | somebody presses the button. That is the module's rule about executing code in
    | a stranger's tab, and it does not relax because the author happens to be us.
    |
    */

    'showcase' => [
        'html' => <<<'HTML'
            <main class="note">
              <h1>Three panes, one frame</h1>
              <p>
                This heading is the HTML pane. The card around it is the CSS pane. The
                counter below is written by the JavaScript pane, and nothing on this page
                came from your account.
              </p>
              <button type="button" id="poke">Count</button>
              <p class="count" id="count" aria-live="polite"></p>
            </main>
            HTML,

        'css' => <<<'CSS'
            body {
              margin: 0;
              min-height: 100vh;
              display: grid;
              place-items: center;
              padding: 1.5rem;
              box-sizing: border-box;
              background: #0b1120;
              color: #e2e8f0;
              font-family: ui-sans-serif, system-ui, -apple-system, sans-serif;
            }

            .note {
              max-width: 30rem;
              padding: 1.75rem;
              border: 1px solid #1e293b;
              border-radius: 16px;
              background: #111827;
              box-shadow: 0 18px 40px -24px rgba(2, 6, 23, 0.9);
            }

            h1 {
              margin: 0 0 0.625rem;
              font-size: 1.25rem;
              font-weight: 600;
              letter-spacing: -0.01em;
            }

            p {
              margin: 0 0 1.25rem;
              font-size: 0.875rem;
              line-height: 1.65;
              color: #94a3b8;
            }

            button {
              font: inherit;
              font-size: 0.875rem;
              padding: 0.5rem 1.1rem;
              border: 1px solid #134e4a;
              border-radius: 999px;
              background: #0f2e2b;
              color: #5eead4;
              cursor: pointer;
            }

            button:hover {
              border-color: #2dd4bf;
            }

            button:active {
              transform: translateY(1px);
            }

            .count {
              margin: 1rem 0 0;
              min-height: 1.2rem;
              font-family: ui-monospace, SFMono-Regular, monospace;
              font-size: 0.8rem;
              color: #5eead4;
            }
            CSS,

        'js' => <<<'JS'
            var output = document.getElementById('count');
            var clicks = 0;

            console.log('Frame ready. Anything this script logs arrives in the panel beside it.');

            document.getElementById('poke').addEventListener('click', function () {
              clicks += 1;

              output.textContent = clicks === 1
                ? 'One click, handled in the JavaScript pane.'
                : clicks + ' clicks. The number is written into the HTML pane\'s element.';

              console.log('click #' + clicks);

              if (clicks === 5) {
                console.warn('Five. A warning is coloured differently from a log.');
              }
            });
            JS,
    ],

];
