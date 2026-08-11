'use strict';

const webpush = require('web-push');
process.stdout.write(JSON.stringify(webpush.generateVAPIDKeys()) + '\n');
