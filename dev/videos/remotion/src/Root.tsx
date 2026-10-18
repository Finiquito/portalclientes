import React from 'react';
import {Composition, continueRender, delayRender, staticFile} from 'remotion';
import {V1} from './V1';
import {DURACION, FPS} from './data';
import {T} from './theme';

// Tipografía del landing (Schibsted Grotesk), cargada antes de dibujar el primer cuadro.
const espera = delayRender('tipografía');
new FontFace('Schibsted', `url(${staticFile('schibsted.woff2')})`, {weight: '400 900'}).load()
  .then(f => { (document as any).fonts.add(f); continueRender(espera); })
  .catch(() => continueRender(espera));

export const Root: React.FC = () => (
  <Composition id="V1" component={V1} durationInFrames={Math.round(DURACION * FPS)} fps={FPS} width={T.W} height={T.H} />
);
