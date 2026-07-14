import { mkdir, readFile, readdir, rm, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const vendorRoot = resolve(repositoryRoot, 'public/assets/vendor');

const runtimeAssets = [
    {
        source: 'node_modules/bootstrap/dist/css/bootstrap.min.css',
        destination: 'bootstrap/bootstrap.min.css',
        transform: stripSourceMapReference,
    },
    {
        source: 'node_modules/bootstrap/dist/js/bootstrap.bundle.min.js',
        destination: 'bootstrap/bootstrap.bundle.min.js',
        transform: stripSourceMapReference,
    },
    {
        source: 'node_modules/jquery/dist/jquery.min.js',
        destination: 'jquery/jquery.min.js',
    },
    {
        source: 'node_modules/apexcharts/dist/apexcharts.min.js',
        destination: 'apexcharts/apexcharts.min.js',
    },
    {
        source: 'node_modules/remixicon/fonts/remixicon.css',
        destination: 'remixicon/remixicon.css',
        transform: keepRemixWoff2Only,
    },
    {
        source: 'node_modules/remixicon/fonts/remixicon.woff2',
        destination: 'remixicon/remixicon.woff2',
        binary: true,
    },
];

// Bootstrap's bundle contains Popper, while ApexCharts contains the listed SVG helpers.
// Their upstream license files therefore accompany the top-level packages we redistribute.
const runtimePackages = [
    { name: 'bootstrap', purpose: 'Bootstrap CSS and JavaScript', license: 'LICENSE', destination: 'bootstrap.txt' },
    { name: '@popperjs/core', purpose: 'Popper, bundled into Bootstrap JavaScript', license: 'LICENSE.md', destination: 'popperjs-core.txt' },
    { name: 'jquery', purpose: 'jQuery JavaScript', license: 'LICENSE.txt', destination: 'jquery.txt' },
    { name: 'remixicon', purpose: 'Remix Icon CSS and WOFF2 font', license: 'License', destination: 'remixicon.txt' },
    { name: 'apexcharts', purpose: 'ApexCharts JavaScript', license: 'LICENSE', destination: 'apexcharts.txt' },
    { name: '@yr/monotone-cubic-spline', purpose: 'bundled into ApexCharts', license: 'LICENSE', destination: 'monotone-cubic-spline.txt' },
    { name: 'svg.js', purpose: 'bundled into ApexCharts', license: 'LICENSE.txt', destination: 'svg-js.txt' },
    { name: 'svg.draggable.js', purpose: 'bundled into ApexCharts', license: 'LICENSE', destination: 'svg-draggable-js.txt' },
    { name: 'svg.easing.js', purpose: 'bundled into ApexCharts', license: 'LICENSE', destination: 'svg-easing-js.txt' },
    { name: 'svg.filter.js', purpose: 'bundled into ApexCharts', license: 'LICENSE', destination: 'svg-filter-js.txt' },
    { name: 'svg.pathmorphing.js', purpose: 'bundled into ApexCharts', license: 'LICENSE', destination: 'svg-pathmorphing-js.txt' },
    { name: 'svg.resize.js', purpose: 'bundled into ApexCharts', license: 'LICENSE', destination: 'svg-resize-js.txt' },
    { name: 'svg.select.js', purpose: 'bundled into ApexCharts', license: 'LICENSE', destination: 'svg-select-js.txt' },
];

const developmentOnlyPackages = [
    ['@axe-core/playwright', 'accessibility test integration'],
    ['axe-core', 'accessibility test engine'],
];

function normalizeText(value) {
    return value.replace(/\r\n?/g, '\n');
}

function stripSourceMapReference(value, source) {
    const matches = value.match(/sourceMappingURL=/g) ?? [];

    if (matches.length !== 1) {
        throw new Error(`${source} must contain exactly one source-map reference; found ${matches.length}.`);
    }

    const output = value.replace(
        /\n?(?:\/\*[#@]\s*sourceMappingURL=[^*\n]+\*\/|\/\/[#@]\s*sourceMappingURL=[^\n]+)\s*$/,
        '\n',
    );

    if (output.includes('sourceMappingURL=')) {
        throw new Error(`Could not remove the source-map reference from ${source}.`);
    }

    return output;
}

function keepRemixWoff2Only(value, source) {
    const fontFaces = value.match(/@font-face\s*\{[\s\S]*?\}/g) ?? [];

    if (fontFaces.length !== 1) {
        throw new Error(`${source} must contain exactly one @font-face block; found ${fontFaces.length}.`);
    }

    const block = fontFaces[0];
    const woff2 = block.match(
        /url\((?:'|")?(remixicon\.woff2[^'")]*)(?:'|")?\)\s*format\((?:'|")woff2(?:'|")\)/,
    );
    const firstSource = block.search(/^\s*src:/m);
    const fontDisplay = block.search(/^\s*font-display:/m);

    if (!woff2 || firstSource < 0 || fontDisplay <= firstSource) {
        throw new Error(`Could not identify the Remix Icon WOFF2 source in ${source}.`);
    }

    const localNotice = '/* Local distribution modification: font sources are limited to the shipped WOFF2 asset. */\n';
    const updatedBlock = `${block.slice(0, firstSource)}  src: url("${woff2[1]}") format("woff2");\n${block.slice(fontDisplay)}`;
    const output = value.replace(block, updatedBlock).replace('@font-face', `${localNotice}@font-face`);

    if (/(?:remixicon\.eot|remixicon\.ttf|remixicon\.svg|remixicon\.woff\?)/.test(output)) {
        throw new Error(`Unshipped Remix Icon font formats remain referenced in ${source}.`);
    }

    return output;
}

async function packageMetadata(packageName) {
    const path = resolve(repositoryRoot, 'node_modules', packageName, 'package.json');
    return JSON.parse(await readFile(path, 'utf8'));
}

async function buildNotices() {
    const runtimeLines = [];
    for (const runtimePackage of runtimePackages) {
        const metadata = await packageMetadata(runtimePackage.name);
        const license = Array.isArray(metadata.license) ? metadata.license.join(', ') : metadata.license;
        runtimeLines.push(`- ${metadata.name} ${metadata.version} (${license}) - ${runtimePackage.purpose}; license: licenses/${runtimePackage.destination}`);
    }

    const developmentLines = [];
    for (const [packageName, purpose] of developmentOnlyPackages) {
        const metadata = await packageMetadata(packageName);
        const license = Array.isArray(metadata.license) ? metadata.license.join(', ') : metadata.license;
        developmentLines.push(`- ${metadata.name} ${metadata.version} (${license}) - ${purpose}`);
    }

    return normalizeText(`THIRD-PARTY NOTICES FOR ADMIN WEB ASSETS

This directory is generated from the exact packages locked in package-lock.json.
The following software is redistributed in the production web application:

${runtimeLines.join('\n')}

Complete upstream license texts are included in the licenses/ directory. Copyright and
license headers embedded in the generated CSS and JavaScript are retained.

Local packaging changes:
- Bootstrap source-map trailers are removed because source maps are not distributed.
- Remix Icon's @font-face declaration is limited to the WOFF2 file that is distributed.

Development and CI only (not copied into public assets or the production runtime):

${developmentLines.join('\n')}

These development-only packages are installed from package-lock.json for accessibility
testing and carry their upstream license files inside their npm packages.
`);
}

async function expectedFiles() {
    const files = new Map();

    for (const asset of runtimeAssets) {
        const source = resolve(repositoryRoot, asset.source);
        const input = await readFile(source);
        let output = input;

        if (!asset.binary) {
            const normalized = normalizeText(input.toString('utf8'));
            output = Buffer.from(asset.transform ? asset.transform(normalized, asset.source) : normalized);
        }

        files.set(asset.destination, output);
    }

    for (const runtimePackage of runtimePackages) {
        const source = resolve(repositoryRoot, 'node_modules', runtimePackage.name, runtimePackage.license);
        const output = normalizeText(await readFile(source, 'utf8'));
        files.set(`licenses/${runtimePackage.destination}`, Buffer.from(output));
    }

    files.set('THIRD_PARTY_NOTICES.txt', Buffer.from(await buildNotices()));

    return files;
}

async function listEntries(directory, prefix = '') {
    let entries;
    try {
        entries = await readdir(directory, { withFileTypes: true });
    } catch (error) {
        if (error.code === 'ENOENT') {
            return [];
        }
        throw error;
    }

    const files = [];
    for (const entry of entries.sort((left, right) => left.name.localeCompare(right.name))) {
        const relativePath = prefix ? `${prefix}/${entry.name}` : entry.name;
        const absolutePath = resolve(directory, entry.name);
        if (entry.isDirectory()) {
            files.push(...await listEntries(absolutePath, relativePath));
        } else {
            files.push({ name: relativePath, regularFile: entry.isFile() });
        }
    }

    return files;
}

async function writeFiles(files) {
    await rm(vendorRoot, { recursive: true, force: true });

    for (const [destination, contents] of files) {
        const target = resolve(vendorRoot, destination);
        await mkdir(dirname(target), { recursive: true });
        await writeFile(target, contents);
    }

    console.log(`Generated ${files.size} admin vendor files.`);
}

async function checkFiles(files) {
    const actualEntries = await listEntries(vendorRoot);
    const actualFiles = actualEntries.filter((entry) => entry.regularFile).map((entry) => entry.name);
    const expectedNames = [...files.keys()].sort();
    const differences = [];

    for (const entry of actualEntries.filter((candidate) => !candidate.regularFile)) {
        differences.push(`unsupported file type: ${entry.name}`);
    }
    for (const missing of expectedNames.filter((name) => !actualFiles.includes(name))) {
        differences.push(`missing: ${missing}`);
    }
    for (const extra of actualFiles.filter((name) => !files.has(name))) {
        differences.push(`unexpected: ${extra}`);
    }
    for (const name of expectedNames.filter((candidate) => actualFiles.includes(candidate))) {
        const actual = await readFile(resolve(vendorRoot, ...name.split('/')));
        if (!actual.equals(files.get(name))) {
            differences.push(`content differs: ${name}`);
        }
    }

    if (differences.length > 0) {
        console.error('Checked-in admin vendor assets do not match package-lock.json:');
        for (const difference of differences) {
            console.error(`- ${difference}`);
        }
        console.error('Run npm run build:vendor and commit the generated changes.');
        process.exitCode = 1;
        return;
    }

    console.log(`Verified ${files.size} checked-in admin vendor files.`);
}

const argument = process.argv[2];
if (argument && argument !== '--check') {
    throw new Error(`Unknown argument: ${argument}`);
}

const files = await expectedFiles();
if (argument === '--check') {
    await checkFiles(files);
} else {
    await writeFiles(files);
}
