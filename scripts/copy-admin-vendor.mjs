import { cp, mkdir } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';

const files = [
    ['node_modules/bootstrap/dist/css/bootstrap.min.css', 'public/assets/vendor/bootstrap/bootstrap.min.css'],
    ['node_modules/bootstrap/dist/js/bootstrap.bundle.min.js', 'public/assets/vendor/bootstrap/bootstrap.bundle.min.js'],
    ['node_modules/jquery/dist/jquery.min.js', 'public/assets/vendor/jquery/jquery.min.js'],
    ['node_modules/apexcharts/dist/apexcharts.min.js', 'public/assets/vendor/apexcharts/apexcharts.min.js'],
    ['node_modules/remixicon/fonts/remixicon.css', 'public/assets/vendor/remixicon/remixicon.css'],
    ['node_modules/remixicon/fonts/remixicon.woff2', 'public/assets/vendor/remixicon/remixicon.woff2'],
];

for (const [source, destination] of files) {
    const target = resolve(destination);
    await mkdir(dirname(target), { recursive: true });
    await cp(resolve(source), target);
}

console.log('Copied ' + files.length + ' admin vendor assets.');
