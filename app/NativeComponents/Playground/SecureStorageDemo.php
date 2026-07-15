<?php

namespace App\NativeComponents\Playground;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Dialog;
use Native\Mobile\Facades\SecureStorage;

class SecureStorageDemo extends NativeComponent
{
    public string $key = '';

    public string $value = '';

    public ?string $retrieved = null;

    /** @var array<string, string> Read by the @nativeError directive. */
    public array $errors = [];

    public function navTitle(): string
    {
        return 'Secure Storage';
    }

    public function store(): void
    {
        $this->errors = [];

        if (trim($this->key) === '') {
            $this->errors['key'] = 'A key is required';
        }

        if (trim($this->value) === '') {
            $this->errors['value'] = 'A value is required';
        }

        if ($this->errors !== []) {
            return;
        }

        SecureStorage::set($this->key, $this->value);
        Dialog::toast("Stored '{$this->key}' securely");
        $this->value = '';
        $this->retrieved = null;
    }

    public function retrieve(): void
    {
        if (! $this->requireKey()) {
            return;
        }

        $this->retrieved = SecureStorage::get($this->key);

        if ($this->retrieved === null) {
            Dialog::toast("Nothing stored under '{$this->key}'");
        }
    }

    public function forget(): void
    {
        if (! $this->requireKey()) {
            return;
        }

        SecureStorage::delete($this->key);
        $this->retrieved = null;
        Dialog::toast("Deleted '{$this->key}'");
    }

    protected function requireKey(): bool
    {
        $this->errors = [];

        if (trim($this->key) === '') {
            $this->errors['key'] = 'A key is required';

            return false;
        }

        return true;
    }

    public function render(): View
    {
        return view('native.playground.secure-storage-demo');
    }
}
