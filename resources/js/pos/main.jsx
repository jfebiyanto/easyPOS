import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './App.jsx';
import SingleTabGate from './SingleTabGate.jsx';

const el = document.getElementById('pos-root');
if (el) {
    createRoot(el).render(
        <SingleTabGate>
            <App />
        </SingleTabGate>,
    );
}
