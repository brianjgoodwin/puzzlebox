import './bootstrap';

import Alpine from 'alpinejs';
import { sudokuGame } from './sudoku';

window.Alpine    = Alpine;
window.sudokuGame = sudokuGame;

Alpine.start();
