import { flushPromises, mount } from '@vue/test-utils';
import EncryptionDialog from '../../frontend/src/components/EncryptionDialog.vue';
import LocalDecryption from '../../frontend/src/components/LocalDecryption.vue';
import { transformFile } from '../../frontend/src/lib/fileEncryption.js';

vi.mock('../../frontend/src/lib/fileEncryption.js', () => ({ ENCRYPTION_FORMAT: 'WBENC001', transformFile: vi.fn() }));

describe('local encryption UI', () => {
  afterEach(() => { vi.restoreAllMocks(); vi.unstubAllGlobals(); });

  it('offers plain uploads only when optional and clears passwords on cancel', async () => {
    const optional = mount(EncryptionDialog);
    expect(optional.text()).toContain('Upload without encryption');
    const required = mount(EncryptionDialog, { props: { required: true } });
    expect(required.text()).not.toContain('Upload without encryption');
    await required.find('input').setValue('long test password');
    await required.findAll('button').find((button) => button.text() === 'Cancel').trigger('click');
    expect(required.emitted('answer')[0][0]).toEqual({ choice: 'cancel', password: '' });
    expect(required.find('input').element.value).toBe('');
  });

  it('rejects mismatched passwords before emitting an upload choice', async () => {
    const wrapper = mount(EncryptionDialog);
    await wrapper.findAll('input')[0].setValue('long test password');
    await wrapper.findAll('input')[1].setValue('different password');
    await wrapper.find('form').trigger('submit');
    expect(wrapper.emitted('answer')).toBeUndefined();
    expect(wrapper.text()).toContain('do not match');
  });

  it('offers no plaintext download on authentication failure and permits retry without refetching', async () => {
    const createUrl = vi.fn(() => 'blob:verified');
    vi.stubGlobal('URL', { createObjectURL: createUrl, revokeObjectURL: vi.fn() });
    const fetcher = vi.fn(async () => ({ ok: true, blob: async () => new Blob(['ciphertext']) }));
    vi.stubGlobal('fetch', fetcher);
    transformFile.mockRejectedValueOnce(new Error('Incorrect password or damaged encrypted file.'));
    const wrapper = mount(LocalDecryption, { props: { url: '/download', name: 'test.txt' } });
    await wrapper.find('button').trigger('click');
    await wrapper.find('input').setValue('wrong');
    await wrapper.find('form').trigger('submit');
    await flushPromises();
    expect(createUrl).not.toHaveBeenCalled();
    expect(wrapper.find('a').exists()).toBe(false);
    transformFile.mockResolvedValueOnce(new Blob(['verified plaintext']));
    await wrapper.findAll('button').find((button) => button.text() === 'Retry password').trigger('click');
    await wrapper.find('input').setValue('correct');
    await wrapper.find('form').trigger('submit');
    await flushPromises();
    expect(fetcher).toHaveBeenCalledTimes(1);
    expect(wrapper.find('a').attributes('download')).toBe('test.txt');
    wrapper.unmount();
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:verified');
  });
});
