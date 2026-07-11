<?php

namespace App\Support;

use App\Models\SalonService;

class FinanceStructure
{
    public const DEFAULT_COST_CENTER = 'general_salon';

    public const DEFAULT_REVENUE_CATEGORY = 'service_income';

    public static function revenueCategories(): array
    {
        return [
            'service_income' => 'Service Income',
            'retail_product_sales' => 'Retail Product Sales',
            'package_sales' => 'Package Sales',
            'gift_card_sales' => 'Gift Card Sales',
            'chair_rental_income' => 'Chair Rental Income',
            'line_rental_income' => 'Line Rental Income',
            'commission_income' => 'Commission Income',
            'other_income' => 'Other Income',
        ];
    }

    public static function expenseCategories(): array
    {
        return [
            'service_consumables' => 'Service Consumables',
            'service_products' => 'Service Products',
            'inventory_purchase' => 'Inventory Purchase',
            'tools_equipment' => 'Tools & Equipment',
            'hospitality' => 'Hospitality',
            'cleaning_hygiene' => 'Cleaning & Hygiene',
            'marketing_branding' => 'Marketing & Branding',
            'payroll_commissions' => 'Payroll & Commissions',
            'rent_utilities' => 'Rent & Utilities',
            'maintenance' => 'Maintenance',
            'transportation_logistics' => 'Transportation & Logistics',
            'software_it' => 'Software & IT',
            'training_development' => 'Training & Development',
            'events_pr' => 'Events & PR',
            'administration_finance' => 'Administration & Finance',
            'miscellaneous' => 'Miscellaneous',
        ];
    }

    public static function expenseCategoryAliases(): array
    {
        return [
            'supplies' => 'service_consumables',
            'rent' => 'rent_utilities',
            'utilities' => 'rent_utilities',
            'marketing' => 'marketing_branding',
            'payroll' => 'payroll_commissions',
            'staff_welfare' => 'hospitality',
            'petty_cash' => 'miscellaneous',
            'transport' => 'transportation_logistics',
            'reimbursements' => 'administration_finance',
            'professional_fees' => 'administration_finance',
            'procurement' => 'inventory_purchase',
            'other' => 'miscellaneous',
        ];
    }

    public static function costCenters(): array
    {
        return [
            'hair_color' => 'Hair Color',
            'hair_extension' => 'Hair Extension',
            'hair_style' => 'Hair Style',
            'hair_cut' => 'Hair Cut',
            'updo' => 'Updo',
            'nail' => 'Nail',
            'manicure' => 'Manicure',
            'pedicure' => 'Pedicure',
            'makeup' => 'Makeup',
            'eyelash' => 'Eyelash',
            'waxing' => 'Waxing',
            'permanent_makeup_rental' => 'Permanent Makeup Rental',
            'general_salon' => 'General Salon',
            'marketing_branding' => 'Marketing & Branding',
            'management_administration' => 'Management & Administration',
        ];
    }

    public static function inventoryCategories(): array
    {
        return [
            'hair_inventory' => 'Hair Inventory',
            'lash_inventory' => 'Lash Inventory',
            'nail_inventory' => 'Nail Inventory',
            'hair_color_inventory' => 'Hair Color Inventory',
            'retail_products' => 'Retail Products',
            'general_consumables' => 'General Consumables',
        ];
    }

    public static function paymentMethods(): array
    {
        return [
            'cash' => 'Cash',
            'card' => 'Card',
            'bank_transfer' => 'Bank Transfer',
            'online_payment_link' => 'Online Payment Link',
            'package_credit' => 'Package Credit',
            'gift_card' => 'Gift Card',
            'split_payment' => 'Split Payment',
            'other' => 'Other',
        ];
    }

    public static function normalizeCostCenter(?string $value): ?string
    {
        $value = trim((string) $value);

        return array_key_exists($value, self::costCenters()) ? $value : null;
    }

    public static function isFallbackCostCenter(?string $value): bool
    {
        return self::normalizeCostCenter($value) === self::DEFAULT_COST_CENTER;
    }

    public static function normalizeExpenseCategory(?string $value): string
    {
        $value = trim((string) $value);
        $aliases = self::expenseCategoryAliases();

        if (isset($aliases[$value])) {
            return $aliases[$value];
        }

        if (array_key_exists($value, self::expenseCategories())) {
            return $value;
        }

        return 'miscellaneous';
    }

    public static function normalizePaymentMethod(?string $value): string
    {
        $value = trim((string) $value);

        return match ($value) {
            'wallet' => 'other',
            default => array_key_exists($value, self::paymentMethods()) ? $value : 'other',
        };
    }

    public static function defaultExpenseCostCenter(?string $category, ?string $subcategory = null): string
    {
        $category = trim((string) $category);
        $subcategory = trim((string) $subcategory);

        return match (true) {
            $category === 'marketing_branding', $subcategory === 'marketing' => 'marketing_branding',
            $category === 'administration_finance', $category === 'software_it', $category === 'training_development' => 'management_administration',
            default => self::DEFAULT_COST_CENTER,
        };
    }

    public static function resolveInvoiceCostCenter(
        ?string $providedCostCenter,
        ?SalonService $service,
        ?string $revenueCategory = null,
        ?string $description = null,
    ): string {
        $normalizedProvided = self::normalizeCostCenter($providedCostCenter);
        $serviceDerived = self::inferCostCenterFromService($service);
        $description = strtolower(trim((string) $description));

        if ($normalizedProvided && $normalizedProvided !== self::DEFAULT_COST_CENTER) {
            return $normalizedProvided;
        }

        if ($service && $serviceDerived !== self::DEFAULT_COST_CENTER) {
            return $serviceDerived;
        }

        return match ($revenueCategory) {
            'line_rental_income', 'commission_income' => 'permanent_makeup_rental',
            'chair_rental_income' => $normalizedProvided ?: self::DEFAULT_COST_CENTER,
            default => ($description !== '' && str_contains($description, 'marketing'))
                ? 'marketing_branding'
                : ($normalizedProvided ?: self::DEFAULT_COST_CENTER),
        };
    }

    public static function resolveRevenueCategory(
        ?string $providedRevenueCategory,
        ?int $salonServiceId = null,
        ?string $description = null,
    ): string {
        $providedRevenueCategory = trim((string) $providedRevenueCategory);

        if ($providedRevenueCategory !== '' && array_key_exists($providedRevenueCategory, self::revenueCategories())) {
            return $providedRevenueCategory;
        }

        return self::inferRevenueCategory($salonServiceId, $description);
    }

    public static function requiresExplicitInvoiceCostCenter(
        ?string $providedCostCenter,
        ?SalonService $service,
        string $resolvedCostCenter,
    ): bool {
        return self::normalizeCostCenter($providedCostCenter) === null
            && $service === null
            && $resolvedCostCenter === self::DEFAULT_COST_CENTER;
    }

    public static function inferRevenueCategory(?int $salonServiceId, ?string $description = null): string
    {
        $description = strtolower(trim((string) $description));

        if ($description !== '' && str_contains($description, 'package session')) {
            return 'package_sales';
        }

        if ($salonServiceId) {
            return 'service_income';
        }

        return 'retail_product_sales';
    }

    public static function inferCostCenterFromService(?SalonService $service): string
    {
        if (! $service) {
            return self::DEFAULT_COST_CENTER;
        }

        $name = strtolower($service->name);
        $category = strtolower((string) $service->category);

        return match (true) {
            str_contains($name, 'extension') => 'hair_extension',
            str_contains($name, 'color'), str_contains($name, 'bleach'), str_contains($name, 'toner') => 'hair_color',
            str_contains($name, 'cut'), str_contains($name, 'trim') => 'hair_cut',
            str_contains($name, 'updo'), str_contains($name, 'bridal') => 'updo',
            str_contains($name, 'manicure') => 'manicure',
            str_contains($name, 'pedicure') => 'pedicure',
            str_contains($name, 'lash'), str_contains($category, 'lash') => 'eyelash',
            str_contains($name, 'wax'), str_contains($category, 'wax') => 'waxing',
            str_contains($name, 'makeup'), str_contains($category, 'makeup') => 'makeup',
            str_contains($name, 'nail'), str_contains($category, 'nail') => 'nail',
            str_contains($name, 'style'), str_contains($name, 'blow'), str_contains($category, 'style') => 'hair_style',
            default => self::DEFAULT_COST_CENTER,
        };
    }
}
