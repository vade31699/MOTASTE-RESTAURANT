<script setup>
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Password Reset Successful" />

    <div class="min-h-screen flex items-center justify-center bg-gray-100">
        <div class="max-w-sm w-full mx-auto bg-white rounded-xl shadow-lg p-8 text-center">
            <div class="mx-auto flex items-center justify-center h-14 w-14 rounded-full bg-green-100 mb-4">
                <svg class="h-7 w-7 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
            </div>

            <h2 class="text-xl font-bold text-gray-900 mb-2">Successfully reset password</h2>
            <p class="text-green-600 font-medium mb-6">Your password has been reset.</p>

            <a
                href="https://motasterestaurant890.laravel.cloud/staff"
                class="inline-block bg-green-600 hover:bg-green-700 text-white font-semibold py-3 px-6 rounded-lg transition-colors duration-200"
            >
                Proceed to motasterestaurant890.laravel.cloud/staff
            </a>

            <p class="mt-4 text-sm text-gray-500">
                <a
                    href="https://motasterestaurant890.laravel.cloud/login"
                    class="hover:text-gray-700 underline"
                >
                    Or go to login page
                </a>
            </p>
        </div>
    </div>
</template>
