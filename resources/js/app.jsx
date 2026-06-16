/**
 * React Application Entry Point
 *
 * This file is the root of the React app. Vite bundles everything that is
 * imported here (and transitively) into a single JS file: public/build/app.jsx.
 *
 * What happens when this file loads in the browser:
 *   1. React initialises its virtual DOM runtime
 *   2. createRoot() creates a React root attached to the #app div in the Blade template
 *   3. render(<DocAssistant />) mounts the component and paints the initial UI
 *   4. React takes over event handling, state updates, and re-renders from here on
 *
 * Virtual DOM vs real DOM:
 *   React keeps a lightweight in-memory copy of the DOM tree (the "virtual DOM").
 *   When state changes (e.g. a new token arrives from the stream), React diffs the
 *   old virtual DOM against the new one and applies only the minimum number of
 *   real DOM mutations. This is why React UIs feel fast even with frequent updates
 *   like streaming text — only the changed text node is updated, not the whole page.
 *
 * 'use client' — not needed here. That's a Next.js directive for Server Components.
 * Plain Vite + Laravel has no server components; everything runs in the browser.
 */

import './bootstrap';
import '../css/app.css';

import React from 'react';
import { createRoot } from 'react-dom/client';
import DocAssistant from './components/DocAssistant';

/*
 * createRoot() is React 18+'s concurrent mode root.
 * It replaces the old ReactDOM.render() from React 17.
 *
 * document.getElementById('app') targets the <div id="app"> in ask.blade.php.
 * If the element doesn't exist, createRoot() throws — so the Blade template
 * must always include the mount point.
 */
const root = createRoot(document.getElementById('app'));

/*
 * render() kicks off the component tree. React calls each component's
 * render function (or function body for function components), builds the
 * virtual DOM, and commits it to the real DOM.
 *
 * StrictMode wraps the app in development to:
 *   - Double-invoke render functions to detect side effects
 *   - Warn about deprecated lifecycle patterns
 *   - Help find issues before they hit production
 * StrictMode is automatically stripped in production builds.
 */
root.render(
    <React.StrictMode>
        <DocAssistant />
    </React.StrictMode>,
);
