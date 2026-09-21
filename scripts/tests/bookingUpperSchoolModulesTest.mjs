import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import ts from 'typescript';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const php = process.argv[2] ?? 'php';
const config = JSON.parse(execFileSync(php, [
  '-d', 'xdebug.mode=off', '-d', `xdebug.log=${process.platform === 'win32' ? 'NUL' : '/dev/null'}`, '-r',
  'require $argv[1]; echo json_encode(GeoFort\\Booking\\BookingProgramConfig::forFrontend(), JSON_THROW_ON_ERROR);',
  path.join(root, 'vendor/autoload.php'),
], { encoding: 'utf8' }));

// Both files have only type imports. Execute the actual shared helper and admin
// validator against PHP output, without a browser, database, build or HTTP server.
async function loadTypeScript(relativePath) {
  const source = fs.readFileSync(path.join(root, relativePath), 'utf8');
  const { outputText } = ts.transpileModule(source, {
    compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ESNext },
  });
  return import(`data:text/javascript;base64,${Buffer.from(outputText).toString('base64')}`);
}
const helpers = await loadTypeScript('resources/js/config/booking/educationModuleHelpers.ts');
const { validateProgramConfigurationStep } = await loadTypeScript('resources/js/admin/validation/programConfigurationStepValidator.ts');
const expectedStandards = ['Klimaat-Experience', 'Voedsel-Innovatie', 'Dynamische-Globe', 'Earth-Watch'];
const expectedChoices = ['Crisismanagement', 'Minecraft-Programmeren', 'Stop-de-Klimaat-Klok'];
let selections = 0;
for (const [level, definition] of Object.entries(config.schoolLevels.voortgezetBovenbouw)) {
  for (const group of Object.keys(definition.groups)) {
    const parameters = {
      config, sector: 'voortgezetBovenbouw', program: 'dag',
      selectedLevels: [level], selectedGroupsByLevel: { [level]: [group] },
    };
    const availableChoiceModules = helpers.getAvailableEducationModuleOptions({ ...parameters, groupType: 'keuze' });
    assert.deepEqual(availableChoiceModules.map(option => option.key), expectedChoices);
    assert.deepEqual(helpers.getAvailableEducationModuleOptions({ ...parameters, groupType: 'standaard' }).map(option => option.key), expectedStandards);
    const context = {
      availableModules: availableChoiceModules,
      supportsChoiceModules: helpers.supportsChoiceModules(parameters),
      confirmations: { module: true },
    };
    assert.equal(context.supportsChoiceModules, true);
    for (const selectedModule of expectedChoices) {
      assert.equal(helpers.isSelectedEducationModuleStillAvailable({ selectedModule, availableChoiceModules }), true);
      assert.deepEqual(validateProgramConfigurationStep('module', { choiceModule: selectedModule }, context), []);
      selections++;
    }
    for (const invalid of [null, 'Earth-Watch', 'Unknown', 'Crisismanagement,Stop-de-Klimaat-Klok']) {
      assert.equal(validateProgramConfigurationStep('module', { choiceModule: invalid }, context).length, 1);
    }
  }
}
assert.equal(helpers.supportsChoiceModules({ config, sector: 'primairOnderwijs', program: 'ochtend' }), false);
assert.deepEqual(helpers.getAvailableEducationModuleOptions({
  config, sector: 'primairOnderwijs', program: 'ochtend', groupType: 'keuze',
  selectedLevels: ['regulier'], selectedGroupsByLevel: { regulier: ['groep7'] },
}), []);
console.log(`OK: ${selections} upper school level/group/module combinations through the shared public/admin helper and admin step validation; morning remains without a choice.`);
