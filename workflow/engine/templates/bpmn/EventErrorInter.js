bpmnEventErrorInter=function(){
VectorFigure.call(this);
//Setting width and height values as per the zoom ratio
if(typeof workflow.zoomWidth != 'undefined' || typeof workflow.zoomHeight != 'undefined')
      this.setDimension(workflow.zoomWidth, workflow.zoomHeight);
else
    this.setDimension(45,45);
this.stroke=2;
};
bpmnEventErrorInter.prototype=new VectorFigure;
bpmnEventErrorInter.prototype.type="bpmnEventErrorInter";
bpmnEventErrorInter.prototype.paint=function(){
VectorFigure.prototype.paint.call(this);
this.graphics.setStroke(this.stroke);
var x_cir = 0;
var y_cir = 0;
this.graphics.setColor("#000000");
this.graphics.drawEllipse(x_cir,y_cir,this.getWidth(),this.getHeight());
var x_cir2=5;
var y_cir2=5;
this.graphics.setColor("#000000");
this.graphics.drawEllipse(x_cir2,y_cir2,this.getWidth()-10,this.getHeight()-10);
//var x=new Array(7,17,24,34,24,17);
//var y=new Array(33,23,33,13,26,16);
var x=new Array(this.getWidth()/6.4,this.getWidth()/2.6,this.getWidth()/1.87,this.getWidth()/1.32,this.getWidth()/1.87,this.getWidth()/2.6);
var y=new Array(this.getHeight()/1.36,this.getHeight()/1.95,this.getHeight()/1.36,this.getHeight()/3.46,this.getHeight()/1.73,this.getHeight()/2.8);
this.graphics.setColor("#ffffff");
this.graphics.fillPolygon(x,y);
this.graphics.setColor("#000000");
this.graphics.drawPolygon(x,y);
this.graphics.paint();
};

