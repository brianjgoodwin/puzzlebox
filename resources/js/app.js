import './bootstrap';

import Alpine from 'alpinejs';
import { sudokuGame }     from './sudoku';
import { cryptogramGame } from './cryptogram';

window.Alpine         = Alpine;
window.sudokuGame     = sudokuGame;
window.cryptogramGame = cryptogramGame;

Alpine.start();
