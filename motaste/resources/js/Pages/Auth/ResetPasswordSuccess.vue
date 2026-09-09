<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head } from '@inertiajs/vue3';

const email = defineProps({
    email: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <GuestLayout>
        <Head title="Password Reset Successful" />

        <div class="min-h-screen flex items-center justify-center bg-gray-100">
            <div class="max-w-md w-full mx-auto bg-white rounded-xl shadow-lg p-8">
                <div class="text-center">
                    <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-green-100 mb-4">
                        <svg class="h-6 w-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                        </svg>
                    </div>

                    <h2 class="text-2xl font-bold text-gray-900 mb-2">Password Reset Successful</h2>
                    <p class="text-green-600 font-medium mb-6">Your password has been reset.</p>

                    <p class="text-gray-500 text-sm mb-6">
                        You can now log in to the staff portal with your new password.
                    </p>

                    <a
                        href="https://motasterestaurant890.laravel.cloud/staff"
                        class="inline-block bg-green-600 hover:bg-green-700 text-white font-semibold py-3 px-6 rounded-lg transition-colors duration-200 text-center"
                    >
                        Proceed to Staff Portal
                    </a>

                    <p class="mt-4 text-center">
                        <a
                            href="https://motasterestaurant890.laravel.cloud/login"
                            class="text-sm text-gray-500 hover:text-gray-700 underline"
                        >
                            Or go to login page
                        </a>
                    </p>
                </div>
            </div>
        </div>
    </GuestLayout>
</template>
