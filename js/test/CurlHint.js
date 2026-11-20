'use strict';
require('../common');

describe('CurlHint', function () {
    function buildDom() {
        document.documentElement.innerHTML =
            '<div id="curlhint" class="hidden">' +
            '<code id="curlcommand"></code>' +
            '<button id="copycurl" type="button"></button>' +
            '</div>';
        PrivateBin.CurlHint.init();
    }

    it('shows the plaintext download command', function () {
        const clean = globalThis.cleanup('', {
            url: 'https://example.com/?my-paste#abc123'
        });
        buildDom();
        PrivateBin.CurlHint.show('my-paste');
        assert.ok(!document.getElementById('curlhint').classList.contains('hidden'));
        assert.strictEqual(
            document.getElementById('curlcommand').textContent,
            // eslint-disable-next-line quotes
            "curl -sL 'https://example.com/?pasteid=my-paste&raw=1&key=abc123' -o 'my-paste.txt'"
        );
        clean();
    });

    it('shows the encrypted download command when no key is available', function () {
        const clean = globalThis.cleanup('', {
            url: 'https://example.com/?my-paste'
        });
        buildDom();
        PrivateBin.CurlHint.show('my-paste');
        assert.strictEqual(
            document.getElementById('curlcommand').textContent,
            // eslint-disable-next-line quotes
            "curl -sL 'https://example.com/?pasteid=my-paste&raw=1' -o 'my-paste.json'"
        );
        clean();
    });

    it('hides the hint', function () {
        const clean = globalThis.cleanup('', {
            url: 'https://example.com/?my-paste#abc123'
        });
        buildDom();
        PrivateBin.CurlHint.show('my-paste');
        PrivateBin.CurlHint.hide();
        assert.ok(document.getElementById('curlhint').classList.contains('hidden'));
        clean();
    });
});
