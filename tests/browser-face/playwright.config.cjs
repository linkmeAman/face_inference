const {defineConfig} = require('@playwright/test');
module.exports = defineConfig({testDir:'.',testMatch:'*.spec.cjs',workers:1,timeout:120000,
    use:{baseURL:'http://127.0.0.1:8137',headless:true},
    webServer:{command:'php -S 127.0.0.1:8137 -t . tests/browser-face/router.php',env:{PHP_CLI_SERVER_WORKERS:'4',FACE_WEB_PILOT_FILE:'/tmp/ccc-browser-face-pilot-fixture.json'},cwd:require('path').resolve(__dirname,'../..'),port:8137,reuseExistingServer:false,stderr:'ignore'}});
