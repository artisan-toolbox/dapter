import { expect, it } from 'vite-plus/test';
import { version } from '@artisan-toolbox/dapter';
import metadata from '../../package.json';

it('exposes the installed frontend package version through its public entry', () => {
    expect(version).toBe(metadata.version);
    expect(version).toMatch(/^\d+\.\d+\.\d+(?:-[\w.]+)?$/);
});
