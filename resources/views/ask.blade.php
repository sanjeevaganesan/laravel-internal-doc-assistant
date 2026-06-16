<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Internal Doc Assistant</title>

    {{--
        @vite() injects the correct <script> and <link> tags for the compiled
        Vite assets. In development it points to the Vite dev server (port 5173)
        and enables Hot Module Replacement (HMR) + React Fast Refresh.
        In production it points to the content-hashed files in public/build/.

        Two entries:
          - resources/css/app.css → compiled Tailwind CSS (output: public/build/app.css)
          - resources/js/app.jsx  → compiled React bundle (output: public/build/app.jsx)

        Contrast with the main (Blade+Alpine.js) branch:
          - No Vite needed there — Alpine.js loaded from CDN, CSS written inline in <style>
          - This branch requires 'npm run dev' before the frontend is visible
    --}}
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
</head>
<body class="bg-slate-900 min-h-screen">

    {{--
        React mount point.

        React takes over this element completely via createRoot().
        Everything inside <div id="app"> is managed by React — including
        the DOM, events, and re-renders. Laravel/Blade just serves this
        shell HTML and Vite injects the compiled React bundle above.

        Why id="app"?
          resources/js/app.jsx contains:
            createRoot(document.getElementById('app')).render(...)
          The id must match exactly.

        Nothing else goes in <body> — React builds the full UI tree.
    --}}
    <div id="app"></div>

</body>
</html>
