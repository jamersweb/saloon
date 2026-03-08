import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import colors from 'tailwindcss/colors';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.jsx',
    ],

    theme: {
        colors: {
            transparent: 'transparent',
            current: 'currentColor',
            black: colors.black,
            white: colors.white,
            red: colors.red,
            green: colors.green,
            emerald: colors.emerald,
            amber: colors.amber,
            zinc: colors.zinc,
            slate: {
                50: '#FAF8F6',
                100: '#F2ECE6',
                200: '#E4D8CC',
                300: '#D7C4B2',
                400: '#B89C8C',
                500: '#A98D86',
                600: '#8C726E',
                700: '#745250',
                800: '#5E4443',
                900: '#423233',
            },
            gray: {
                50: '#FAF8F6',
                100: '#F2ECE6',
                200: '#E4D8CC',
                300: '#D7C4B2',
                400: '#B89C8C',
                500: '#A98D86',
                600: '#8C726E',
                700: '#745250',
                800: '#5E4443',
                900: '#423233',
            },
            indigo: {
                50: '#FAEFF0',
                100: '#F4DEE0',
                200: '#E9C0C2',
                300: '#DFA194',
                400: '#D48887',
                500: '#C87374',
                600: '#B26366',
                700: '#965456',
                800: '#7E4748',
                900: '#693C3D',
            },
        },
        extend: {
            fontFamily: {
                sans: ['Alex', 'Times New Roman', ...defaultTheme.fontFamily.serif],
                display: ['Avilla Mirabel', 'Times New Roman', ...defaultTheme.fontFamily.serif],
            },
        },
    },

    plugins: [forms],
};
