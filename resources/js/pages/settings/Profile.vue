<script setup lang="ts">
import type { StudentUser } from '@/types/user';
import type { Page } from '@inertiajs/core';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type BreadcrumbItem } from '@/types';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';

interface Props {
    mustVerifyEmail: boolean;
    status?: string;
}
defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Profile settings',
        href: route('profile.edit'),
    },
];

type AppPageProps = Page['props'] & {
    auth: {
        user: StudentUser;
    };
    latestAssessmentInfo?: {
        year_level: string;
        semester: string;
        school_year: string;
    } | null;
};

const page = usePage<AppPageProps>();
const user = computed(() => page.props.auth.user);

// Year level should reflect latest assessment, not the potentially-stale users.year_level
const displayYearLevel = computed(() => {
    const assessment = (page.props as any).latestAssessmentInfo;
    if (assessment?.year_level) return assessment.year_level;
    return user.value.year_level ?? '';
});

// Safely resolve the role regardless of Enum or string
const userRole = computed(() => {
    const role = (user.value as any).role;
    if (!role) return 'student';
    if (typeof role === 'string') return role;
    return role.value ?? role.name ?? 'student';
});

const isStudent = computed(() => userRole.value === 'student');
const isAccountingOrAdmin = computed(() => ['accounting', 'admin'].includes(userRole.value));
const isAdmin = computed(() => userRole.value === 'admin');

// Normalize status
const initialStatus = computed(() => {
    const s = (user.value as any).status;
    if (!s) return 'active';
    if (typeof s === 'string') return s;
    return s.value ?? s.name ?? 'active';
});

// Format birthday for date input (YYYY-MM-DD)
const formatBirthday = (birthday: any): string => {
    if (!birthday) return '';
    if (typeof birthday === 'string') {
        if (/^\d{4}-\d{2}-\d{2}$/.test(birthday)) return birthday;
        try {
            return new Date(birthday).toISOString().split('T')[0];
        } catch {
            return '';
        }
    }
    return '';
};

const form = useForm({
    last_name:                 user.value.last_name ?? '',
    first_name:                user.value.first_name ?? '',
    middle_initial:            user.value.middle_initial ?? '',
    email:                     user.value.email ?? '',
    birthday:                  formatBirthday(user.value.birthday),
    // Address fields — keys now match DB column names exactly
    address_house_lot_unit:    (user.value as any).address_house_lot_unit ?? '',
    address_street_name:       (user.value as any).address_street_name ?? '',
    address_barangay:          (user.value as any).address_barangay ?? '',
    address_municipality_city: (user.value as any).address_municipality_city ?? '',
    address_province:          (user.value as any).address_province ?? 'Sorsogon',
    phone:                     user.value.phone ?? '',
    account_id:                user.value.account_id ?? '',
    course:                    user.value.course ?? '',
    year_level:                user.value.year_level ?? '',
    faculty:                   user.value.faculty ?? '',
    status:                    initialStatus.value,
});

const submit = () => {
    form.patch(route('profile.update'), {
        preserveScroll: true,
        onSuccess: () => {
            // After successful update, reload auth data to ensure avatar/email changes are reflected
            router.reload({ only: ['auth'] });
        },
        onError: (errors: any) => {
            console.error('Profile update errors:', errors);
        },
    });
};

// ─── PROFILE PICTURE ─────────────────────────────────────────────────────────

// Use the full avatar URL (already resolved by middleware) for the preview
const profilePicturePreview = ref<string | null>(user.value.avatar ?? null);
const profilePictureError = ref<string | undefined>();
const profilePictureInput = ref<HTMLInputElement | null>(null);

const profilePictureForm = useForm<{ profile_picture: File | null }>({
    profile_picture: null,
});

const selectProfilePicture = () => {
    profilePictureInput.value?.click();
};

const updateProfilePicturePreview = (event: Event) => {
    const target = event.target as HTMLInputElement;
    if (!target.files || target.files.length === 0) return;

    const file = target.files[0];
    profilePictureForm.profile_picture = file;

    // Show local preview immediately
    const reader = new FileReader();
    reader.onload = (e) => {
        profilePicturePreview.value = e.target?.result as string;
    };
    reader.readAsDataURL(file);

    profilePictureForm.post(route('profile.update-picture'), {
        forceFormData: true,
        onError: (errors) => {
            profilePictureError.value = (errors as any).profile_picture ?? undefined;
            // Revert preview on error
            profilePicturePreview.value = user.value.avatar ?? null;
        },
        onSuccess: () => {
            profilePictureError.value = undefined;
            // Use Inertia's reload to refresh shared props (including auth.user.avatar)
            // instead of window.location.reload() which breaks SPA state
            router.reload({ only: ['auth'] });
        },
    });
};

const removeProfilePicture = () => {
    router.delete(route('profile.remove-picture'), {
        onSuccess: () => {
            profilePicturePreview.value = null;
        },
    });
};

const hasProfilePicture = computed(() => !!profilePicturePreview.value);

const profileInitial = computed(() => {
    if (form.first_name) return form.first_name.charAt(0).toUpperCase();
    return user.value.name?.charAt(0)?.toUpperCase() ?? '?';
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Profile settings" />

        <SettingsLayout>
            <div class="space-y-6">
                <!-- PROFILE PICTURE SECTION -->
                <div class="mb-8 flex flex-col space-y-6">
                    <HeadingSmall title="Profile Picture" description="Update your profile picture" />
                    <div class="flex items-center space-x-6">
                        <div class="shrink-0">
                            <img
                                v-if="profilePicturePreview"
                                :src="profilePicturePreview"
                                class="h-20 w-20 rounded-full border object-cover"
                                alt="Profile preview"
                            />
                            <div v-else class="flex h-20 w-20 items-center justify-center rounded-full border bg-muted">
                                <span class="text-lg font-medium text-muted-foreground">
                                    {{ profileInitial }}
                                </span>
                            </div>
                        </div>

                        <div>
                            <input
                                ref="profilePictureInput"
                                type="file"
                                class="hidden"
                                accept="image/jpeg,image/png,image/gif,image/webp"
                                @change="updateProfilePicturePreview"
                                autocomplete="off"
                            />
                            <Button type="button" variant="outline" @click="selectProfilePicture" :disabled="profilePictureForm.processing">
                                <span v-if="profilePictureForm.processing">Uploading...</span>
                                <span v-else>Select New Photo</span>
                            </Button>
                            <div v-if="hasProfilePicture" class="mt-2">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    @click="removeProfilePicture"
                                    :disabled="profilePictureForm.processing"
                                >
                                    Remove
                                </Button>
                            </div>
                            <InputError class="mt-2" :message="profilePictureError" />
                        </div>
                    </div>
                </div>

                <!-- PROFILE INFO FORM -->
                <form @submit.prevent="submit" class="mt-8 space-y-6">
                    <HeadingSmall
                        title="Profile information"
                        :description="isStudent ? 'Update your student account information' : 'Update your account information'"
                    />

                    <div class="grid gap-2">
                        <Label for="last_name">Last Name <span class="text-red-500">*</span></Label>
                        <Input id="last_name" v-model="form.last_name" autocomplete="family-name" required placeholder="Dela Cruz" />
                        <InputError class="mt-2" :message="form.errors.last_name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="first_name">First Name <span class="text-red-500">*</span></Label>
                        <Input id="first_name" v-model="form.first_name" autocomplete="given-name" required placeholder="Juan" />
                        <InputError class="mt-2" :message="form.errors.first_name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="middle_initial">Middle Initial</Label>
                        <Input id="middle_initial" v-model="form.middle_initial" autocomplete="additional-name" placeholder="P" maxlength="1" />
                        <InputError class="mt-2" :message="form.errors.middle_initial" />
                    </div>

                    <!-- Account ID (Students Only, Read Only) -->
                    <div v-if="isStudent" class="grid gap-2">
                        <Label for="account_id">Account ID</Label>
                        <div class="flex items-center rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-gray-600">
                            <span class="font-medium">{{ form.account_id || 'Not assigned' }}</span>
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="email">Email address <span class="text-red-500">*</span></Label>
                        <Input id="email" v-model="form.email" type="email" autocomplete="email" required placeholder="student@ccdi.edu.ph" />
                        <InputError class="mt-2" :message="form.errors.email" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="birthday">Birthday</Label>
                        <Input
                            id="birthday"
                            v-model="form.birthday"
                            type="date"
                            autocomplete="bday"
                            min="1900-01-01"
                            :max="new Date().toISOString().split('T')[0]"
                        />
                        <InputError class="mt-2" :message="form.errors.birthday" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="phone">Phone</Label>
                        <Input id="phone" v-model="form.phone" autocomplete="tel" placeholder="09171234567" />
                        <InputError class="mt-2" :message="form.errors.phone" />
                    </div>

                    <div class="grid gap-2 col-span-full">
                        <Label>Address</Label>
                        <div class="grid gap-2 rounded-md border border-input p-3">
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <Input v-model="form.address_house_lot_unit" placeholder="Unit/Lot No." />
                                    <InputError class="mt-1" :message="form.errors.address_house_lot_unit" />
                                </div>
                                <div>
                                    <Input v-model="form.address_street_name" placeholder="Street Name" />
                                    <InputError class="mt-1" :message="form.errors.address_street_name" />
                                </div>
                            </div>
                            <div class="grid grid-cols-3 gap-2">
                                <div>
                                    <Input v-model="form.address_barangay" placeholder="Barangay" />
                                    <InputError class="mt-1" :message="form.errors.address_barangay" />
                                </div>
                                <div>
                                    <Input v-model="form.address_municipality_city" placeholder="City/Municipality" />
                                    <InputError class="mt-1" :message="form.errors.address_municipality_city" />
                                </div>
                                <div>
                                    <Input v-model="form.address_province" placeholder="Province" />
                                    <InputError class="mt-1" :message="form.errors.address_province" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Faculty (Accounting/Admin Only) -->
                    <div v-if="isAccountingOrAdmin" class="grid gap-2">
                        <Label for="faculty">Faculty/Department</Label>
                        <Input id="faculty" v-model="form.faculty" autocomplete="organization" placeholder="e.g., Accounting Department" />
                        <InputError class="mt-2" :message="form.errors.faculty" />
                    </div>

                    <!-- Course (Students Only, Read Only) -->
                    <div v-if="isStudent" class="grid gap-2">
                        <Label for="course">Course</Label>
                        <div class="flex items-center rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-gray-700">
                            <span class="font-medium">{{ form.course || 'Not assigned' }}</span>
                        </div>
                    </div>

                    <!-- Year Level (Students Only, from latest assessment) -->
                    <div v-if="isStudent" class="grid gap-2">
                        <Label for="year_level">Year Level</Label>
                        <div class="flex items-center rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-gray-700">
                            <span class="font-medium">{{ displayYearLevel || 'Not assigned' }}</span>
                        </div>
                    </div>

                    <!-- Status (Students Only — admin can edit, others see read-only) -->
                    <div v-if="isStudent" class="grid gap-2">
                        <Label for="status">Status</Label>
                        <select
                            v-if="isAdmin"
                            id="status"
                            v-model="form.status"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-transparent focus:ring-2 focus:ring-blue-500"
                            autocomplete="off"
                        >
                            <option value="active">Active - {{ user.is_irregular ? 'Irregular' : 'Regular' }}</option>
                            <option value="graduated">Graduated</option>
                            <option value="dropped">Dropped</option>
                        </select>
                        <div v-else class="w-full rounded border bg-gray-50 px-3 py-2 text-gray-700 capitalize">
                            {{ form.status }} -
                            <span
                                :class="[
                                    'rounded-full px-3 py-1 text-xs font-semibold',
                                    user.is_irregular ? 'bg-amber-100 text-amber-700' : 'bg-blue-100 text-blue-700',
                                ]"
                                >{{ user.is_irregular ? 'Irregular' : 'Regular' }}</span
                            >
                        </div>
                        <InputError class="mt-2" :message="form.errors.status" />
                    </div>

                    <!-- Student Classification Badge (Admin-only duplicate — kept for admin context) -->
                    <div v-if="isStudent && isAdmin" class="text-sm text-gray-600">
                        <span class="capitalize">Status - {{ user.status || 'active' }} - </span>
                        <span
                            :class="[
                                'rounded-full px-3 py-1 text-xs font-semibold',
                                user.is_irregular ? 'bg-amber-100 text-amber-700' : 'bg-blue-100 text-blue-700',
                            ]"
                            >{{ user.is_irregular ? 'Irregular' : 'Regular' }}</span
                        >
                    </div>

                    <!-- Email verification notice -->
                    <div v-if="mustVerifyEmail && !user.email_verified_at">
                        <p class="-mt-4 text-sm text-muted-foreground">
                            Your email address is unverified.
                            <Link
                                :href="route('verification.send')"
                                as="button"
                                class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                            >
                                Click here to resend the verification email.
                            </Link>
                        </p>
                        <div v-if="status === 'verification-link-sent'" class="mt-2 text-sm font-medium text-green-600">
                            A new verification link has been sent to your email address.
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <Button type="submit" :disabled="form.processing">Save</Button>
                        <Transition
                            enter-active-class="transition ease-in-out"
                            enter-from-class="opacity-0"
                            leave-active-class="transition ease-in-out"
                            leave-to-class="opacity-0"
                        >
                            <p v-show="form.recentlySuccessful" class="text-sm text-neutral-600">Saved.</p>
                        </Transition>
                    </div>
                </form>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>