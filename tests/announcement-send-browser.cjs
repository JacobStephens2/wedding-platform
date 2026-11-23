const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const { createInterface } = require('node:readline');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

async function checkSendFromPreview() {
    const fixture = spawn('php', ['-r', String.raw`
require 'vendor/autoload.php';
$db = new \Wedding\Tests\support\LocalMariaDb();
try {
    \Wedding\Tests\support\AnnouncementHttp::createSchema($db);
    \Wedding\Tests\support\AnnouncementHttp::seed($db);
    $http = new \Wedding\Tests\support\AnnouncementHttp($db);
    try {
        echo $http->origin . PHP_EOL;
        fgets(STDIN);
        echo json_encode($http->sentMessages(), JSON_THROW_ON_ERROR) . PHP_EOL;
    } finally {
        $http->close();
    }
} finally {
    $db->close();
}
`], { cwd: `${__dirname}/..`, stdio: ['pipe', 'pipe', 'inherit'] });
    const exited = new Promise(resolve => {
        fixture.once('error', resolve);
        fixture.once('close', resolve);
    });
    const output = createInterface({ input: fixture.stdout });
    const lines = output[Symbol.asyncIterator]();
    let browser;
    try {
        const { value: origin } = await lines.next();
        assert.match(origin ?? '', /^http:\/\/127\.0\.0\.1:\d+$/);
        browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH, timeout: 5000 });
        const context = await browser.newContext();
        try {
            await context.addCookies([{ name: 'PHPSESSID', value: 'announcements-test', url: origin }]);
            const page = await context.newPage();
            page.setDefaultTimeout(8000);
            const route = `${origin}/admin-announcements`;
            await page.goto(route);
            const panel = page.locator('details[data-audience="all_with_email"]');
            await panel.locator('summary').click();
            await panel.getByRole('button', { name: 'Select none' }).click();
            await page.locator('#subject').fill('Hello {{first_name}}');
            await page.locator('#custom_recipients').fill('Local Recipient <alex@EXAMPLE.com>');
            await page.getByRole('button', { name: 'Preview recipients' }).click();
            await page.getByRole('heading', { name: 'Preview: 1 recipient' }).waitFor();
            assert.equal(await panel.locator('input[type="checkbox"][name]:checked').count(), 0);

            page.once('dialog', dialog => dialog.accept());
            const [response] = await Promise.all([
                page.waitForResponse(reply => reply.url() === route && reply.request().method() === 'POST'),
                page.getByRole('button', { name: 'Send to all recipients' }).click(),
            ]);
            const submitted = new URLSearchParams(response.request().postData());
            assert.equal(submitted.get('action'), 'send_blast');
            assert.equal(response.status(), 200);
            await page.getByText('Sent to all 1 recipients.', { exact: true }).waitFor();

            fixture.stdin.end();
            const { value: sent } = await lines.next();
            assert.deepEqual(JSON.parse(sent), 'alex@EXAMPLE.com\tHello Local\n');
            assert.equal(await exited, 0);
            console.log('Confirmed Send delivered exactly one typed recipient.');
        } finally {
            await context.close();
        }
    } finally {
        fixture.stdin.end();
        await exited;
        await browser?.close();
        output.close();
    }
}

checkSendFromPreview().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
