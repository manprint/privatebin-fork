'use strict';
require('../common');

describe('CurlHint', function () {
    function buildDom() {
        document.documentElement.innerHTML =
            '<div id="curlhint" class="hidden">' +
            '<code id="curlcommand"></code>' +
            '<code id="decryptcommand"></code>' +
            '<button id="copycurl" type="button"></button>' +
            '</div>';
        PrivateBin.CurlHint.init();
    }

    it('shows the curl download and decryption commands', function () {
        const clean = globalThis.cleanup('', {
            url: 'https://example.com/?my-paste#abc123'
        });
        buildDom();
        PrivateBin.CurlHint.show('my-paste');
        assert.ok(!document.getElementById('curlhint').classList.contains('hidden'));
        assert.strictEqual(
            document.getElementById('curlcommand').textContent,
            // eslint-disable-next-line quotes
            "curl -sL 'https://example.com/?pasteid=my-paste&raw=1' -o 'my-paste.json'"
        );
        assert.strictEqual(
            document.getElementById('decryptcommand').textContent,
            // eslint-disable-next-line quotes
            "php bin/privatebin-decrypt.php 'my-paste.json' 'abc123'"
        );
        clean();
    });

    it('uses a placeholder when no key is available', function () {
        const clean = globalThis.cleanup('', {
            url: 'https://example.com/?my-paste'
        });
        buildDom();
        PrivateBin.CurlHint.show('my-paste');
        assert.ok(
            document.getElementById('decryptcommand').textContent.indexOf('<KEY>') !== -1
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
