import { registerBlockType } from '@wordpress/blocks';

import edit from './edit';
import metadata from './block.json';
import save from './save';
import './style.scss';

registerBlockType( metadata, { edit, save } );
